# TicoScope

**Status: experimental, pre-v0.1.** This package is under active initial
development. It is not yet feature-complete, not yet published to Packagist,
and not yet safe to depend on for real deployments. See
[VISION.md](VISION.md) for the full design and roadmap.

## What this is (eventually)

A diff-based, Laravel-aware pre-deployment risk report:
`php artisan ticoscope:check --base=main` will inspect what changed between
two git revisions and flag operationally significant changes — risky
migrations, config/env footguns, breaking queued-job changes, and more —
before you deploy. See [VISION.md](VISION.md) for the full problem statement
and scope.

## Current state (Milestone 13)

- A Composer-installable Laravel package, with a service provider and
  auto-discovery.
- A real Git diff engine (`GitDiffReader`): merge-base-relative comparison
  between a base and head revision, with rename detection, per-file patch
  capture, and reading a file's content at an arbitrary revision.
- Laravel-aware file classification (`FileClassifier`): migrations, config
  files, routes, queued Jobs, `.env.example`, and Composer files are
  recognized by path, either by a file's current path or (for rules that
  need it) its pre-change path.
- Fifteen real analysis rules, closing out VISION.md's entire Queue Job
  category, its entire Migration rule set, its Composer rule set, and its
  Config/env rule set:
  - `config.env-without-default` flags a newly introduced `env()` call in a
    config file with no usable fallback (including an explicit `null`
    fallback) — the `config:cache` hazard VISION.md calls out as the
    strongest single feature to build first. It only looks at lines the
    diff actually added, so an existing, untouched `env()` call never
    re-fires just because an unrelated line in the same file changed.
  - `config.env-missing-from-example` flags a newly-added `env()` call,
    anywhere in changed application/config PHP code (not just
    `config/*.php`, deliberately broader than the rule above), whose
    referenced variable is absent from `.env.example`'s current declared
    keys. `tests/` is deliberately excluded — test code legitimately
    references variables that should never appear in `.env.example`, and
    flagging those would be a false positive. **Known limitation, stated
    here rather than discovered the hard way:** this rule is diff-scoped,
    not a full-codebase reconciliation — a key silently removed from
    `.env.example` while the code that reads it goes untouched elsewhere
    in the repo is not caught. Catching that would require enumerating and
    scanning every tracked file at head revision, a materially bigger
    primitive than a diff of what changed, and a real departure from this
    project's diff-first architecture, not attempted for v0.1.
  - `queue.job-fqcn-changed` flags a change to a queued Job class's
    fully-qualified class name — a renamed namespace or class, independent
    of whether Git reports the change as a plain edit or a file rename —
    since already-queued jobs referencing the old name may fail to
    deserialize after deploy.
  - `queue.job-class-removed` flags a queued Job class being deleted
    outright, for the same reason.
  - `queue.job-property-removed` flags a public property (declared
    traditionally or via constructor promotion) removed from a Job class —
    an already-queued payload unserializes it back as an undeclared dynamic
    property, deprecated since PHP 8.2.
  - `queue.job-property-retyped` flags a public property whose declared
    type changed — verified directly to throw a real `TypeError` on
    unserialize when the queued value doesn't match the new type.
  - `queue.job-connection-changed` / `queue.job-queue-changed` flag the Job
    class's own declared `$connection`/`$queue` literal changing — an
    operational routing risk (dispatched jobs going to an unmonitored
    connection/queue), not a crash, so kept at the same `Warning` severity
    for a different reason than the identity/property rules above.
  - `migration.column-dropped` / `migration.table-dropped` flag a migration
    dropping a column or table outright — destructive, typically
    irreversible operations, and the first `Critical`-severity findings in
    the project. Correctly tracks the actual Blueprint variable through a
    migration's `Schema::table()`/`Schema::create()` closure (any parameter
    name, typed or not), including nested closures — verified to never
    misattribute a same-named variable shadowed by an inner closure to the
    outer schema builder.
  - `migration.column-renamed` flags a migration renaming a column via
    `renameColumn()` — not destructive to data like the drop rules above,
    but application code and in-flight queries still referencing the old
    column name break the moment the migration runs, so it's kept at
    `Warning` severity, the same class of risk as the Queue identity-change
    rules. Reuses the same Blueprint-tracking extractor as the drop rules,
    with no extractor changes needed.
  - `migration.non-nullable-without-default` flags a column added to an
    *existing* table (`Schema::table()`, never `Schema::create()` — a brand
    new table has no rows yet) with no `->nullable()` and no `->default()`
    in its chain. Only actually harmful if the table already has rows,
    which static analysis can't know, so it's `Warning`, not `Critical`.
    The candidate column-type allowlist and the methods excluded from it
    (`timestamps()`, `softDeletes()`, `rememberToken()`, `id()`, and the
    `morphs()` family) were verified directly against the real
    `Illuminate\Database\Schema\Blueprint` source rather than assumed —
    several of those are already nullable, or already carry an effective
    default, internally.
  - `migration.column-type-changed` flags a column redefined via
    `->change()` on an existing table. This one is a deliberately honest
    heuristic, not a real comparison: a migration only ever states the new
    column definition, never the old one, so TicoScope has no way to know
    whether a given `->change()` actually narrows the column (risking
    truncation) or widens it (completely safe) — every `->change()` on a
    recognized column-type method is flagged equally, at `Warning`, with
    the finding's own message saying this explicitly rather than
    overclaiming certainty. Reconstructing a column's real previous type
    would require replaying a table's entire migration history, a
    materially bigger primitive than a diff of what changed, and was
    deliberately not attempted for v0.1 — see VISION.md §4's own
    acknowledgment that pattern-based migration rules necessarily produce
    both false positives (a harmless widening `->change()`) and false
    negatives (a risky change expressed via raw SQL instead of the
    Blueprint fluent API).
  - `composer.package-major-bump` flags a dependency whose *resolved*
    version in `composer.lock` changed major version — direction-agnostic
    (a downgrade is just as much a signal as a bump). This is the first
    `Info`-severity rule in the project: a major version change might
    introduce a breaking change worth a look, but frequently doesn't for
    a given consumer's actual usage, a real step down from every other
    rule's concrete, specific operational risk. Known limitation, stated
    here rather than discovered the hard way: only the leading version
    component is compared, so a `0.x` release's minor-version breaking
    changes (semver gives no stability guarantee below `1.0.0`) are not
    flagged — a deliberate v0.1 simplification, not an oversight.
  - `composer.package-removed` flags a dependency present in the old
    `composer.lock` and entirely absent from the new one — `Warning`
    severity, one step above the major-bump rule, since a removed
    dependency is a certainty (a class-not-found failure at runtime for
    any code still referencing it), not merely a possibility. Both
    Composer rules read only `composer.lock`'s resolved versions, never
    `composer.json`'s constraints, and only compare `Modified` lock files
    — a newly added or fully deleted `composer.lock` has no "before" or
    "after" side to compare against, so neither rule fires on those.
- `ticoscope:check` runs the real diff and all fifteen rules, prints the
  changed-file list (with classification), and renders findings through a
  real `ConsoleReporter` — grouped by severity (critical, then warning, then
  info), most severe first.
- `--fail-on={info|warning|critical}` gates the command's exit code on
  finding severity, so `ticoscope:check` can be used as a real CI check.
- `--format={console|json}` selects the output format. `console` (the
  default) is unchanged from before. `json` prints a single, versioned
  JSON document (`schema_version`, `summary`, `findings`) to stdout — and
  *only* that document, with no human narration mixed in — for
  programmatic consumers such as `ticoscope:check --format=json | jq
  '.findings'`. Every failure path (an invalid option, a Git error) also
  emits valid JSON under `--format=json`, with the same exit code as
  console mode; exit-code semantics are otherwise unaffected by `--format`.
- The core domain vocabulary the rest of the tool is built on: `ChangedFile`,
  `Diff`, `Finding`, `Severity`, `Rule`, `Analyzer`, `Reporter`.

Not yet implemented: route rules, CI/GitHub Action integration.

## Installation

```bash
composer require ticoscope/laravel
```

Not yet published to Packagist — install from a local path repository or VCS
reference until a first tagged release exists.

## Usage

```bash
php artisan ticoscope:check --base=main --fail-on=warning
```

```
TicoScope
Comparing main → feature

2 files changed

MODIFIED  app/Jobs/GenerateReport.php [queue-job]
MODIFIED  config/services.php [config]

WARNING (2)
  ! config/services.php
    [config.env-without-default] New env() call for REPORTING_ENDPOINT has no fallback. If the variable is missing when configuration is cached, this config value may resolve to null.
  ! app/Jobs/GenerateReport.php
    [queue.job-fqcn-changed] Job class identity changed from App\Jobs\GenerateReport to App\Jobs\Reporting\GenerateReport. Jobs queued under the previous class name may no longer deserialize or resolve correctly after deployment.
2 findings (2 warning).
```

`--fail-on` is optional and lowercase-only (`info`, `warning`, or
`critical`). Omit it to always exit successfully regardless of findings;
set it to gate CI on a minimum severity — the command exits non-zero as
soon as any finding meets or exceeds that threshold.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT. See [LICENSE](LICENSE).
