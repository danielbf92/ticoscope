# TicoScope

**Status: ready for a v0.1.0 tag.** Every rule in v0.1's scope (see
[VISION.md](VISION.md) §5) is implemented and tested. This package has
not yet been tagged or published to Packagist — until a tagged release
exists, install it from a local path repository or a VCS reference (see
[Installation](#installation)). See [VISION.md](VISION.md) for the full
design, scope, and non-goals.

## What this is (eventually)

A diff-based, Laravel-aware pre-deployment risk report:
`php artisan ticoscope:check --base=main` will inspect what changed between
two git revisions and flag operationally significant changes — risky
migrations, config/env footguns, breaking queued-job changes, and more —
before you deploy. See [VISION.md](VISION.md) for the full problem statement
and scope.

## Current state (v0.1, feature-complete)

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

Deliberately not in v0.1 scope, and not a gap in the above: **route rules**
were never part of v0.1 (VISION.md §4 explicitly says ship them only once
there's a concrete spec for what "risky" means for a route change — that
spec doesn't exist yet), and a **first-party GitHub Action/PR-comment bot**
is stated future work (VISION.md §11), a thin consumer of the existing
`--format=json` output, not a new analysis surface.

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

Machine-readable output for CI, a PR-comment bot, or any other
integration:

```bash
php artisan ticoscope:check --base=main --format=json --fail-on=critical
```

```json
{
    "schema_version": "1",
    "summary": {
        "total": 2,
        "critical": 0,
        "warning": 2,
        "info": 0
    },
    "findings": [
        {
            "rule_id": "config.env-without-default",
            "severity": "warning",
            "file": "config/services.php",
            "message": "New env() call for REPORTING_ENDPOINT has no fallback. If the variable is missing when configuration is cached, this config value may resolve to null.",
            "reason_code": "REPORTING_ENDPOINT"
        },
        {
            "rule_id": "queue.job-fqcn-changed",
            "severity": "warning",
            "file": "app/Jobs/GenerateReport.php",
            "message": "Job class identity changed from App\\Jobs\\GenerateReport to App\\Jobs\\Reporting\\GenerateReport. Jobs queued under the previous class name may no longer deserialize or resolve correctly after deployment.",
            "reason_code": null
        }
    ]
}
```

With `--format=json`, stdout carries *only* the JSON document — no human
narration, safe to pipe directly into `jq` or any JSON consumer. Every
failure path (an invalid option, a Git error) also emits valid JSON under
`--format=json`, with the same exit code as console mode.

## JSON output & compatibility

`schema_version` is a stated compatibility promise, not an incidental
field (VISION.md §10 calls this "the one part of the public API that's
expensive to break silently"):

- **Within `schema_version: "1"`, changes are additive only** — a new
  field may be added to the document, `summary`, or a finding object, but
  no existing field is ever renamed, removed, or repurposed, and no
  existing field's type changes.
- **A breaking change** (removing/renaming a field, changing a field's
  type or meaning, changing `findings`' ordering guarantee) **increments
  `schema_version`** to `"2"`, never silently changes what `"1"` means. A
  consumer that only reads fields it recognizes and ignores unknown ones
  is safe across additive changes without ever needing to branch on
  `schema_version`.
- **New rule ids may be added** at any time — a consumer should not assume
  the `rule_id` set is closed. An **existing rule id, once shipped, is
  not renamed** (see CONTRIBUTING.md) — the same compatibility discipline
  that protects `schema_version` extends to the values inside it.
- `reason_code` is always present as a key on every finding, `null` when
  a rule has none to give — never conditionally omitted, so a consumer
  never needs to check for key existence before reading it.

## Known Limitations

This section is a map to the specific limitations, not a restatement of
them — each rule's own README bullet above and VISION.md §4 are the
canonical source for exact false-positive/false-negative behavior.
TicoScope's boundaries all come from the same architectural choice: **it
is entirely static analysis of git diffs and file content.** It never
executes code, never runs a migration (not even `--pretend`), never
introspects a live database or queue, and never makes a network call.
That's a deliberate trade-off for speed, safety, and zero-config CI use
(VISION.md §7), and it shapes every rule's limitations along the same
few lines:

- **Pattern-based, not size/shape-aware.** A rule recognizes a specific
  code pattern (`dropColumn`, a missing `->nullable()`, a major
  `composer.lock` version bump); it has no idea whether the affected
  table has ten rows or ten million, or whether a dependency's breaking
  change actually affects this codebase's usage. This is why several
  rules are `Warning`, not `Critical` — see each rule's own entry above
  for its specific reasoning.
- **Diff-scoped, not a full-codebase audit.** Most rules only look at
  what a diff actually added (`AddedLineExtractor`) so that untouched,
  pre-existing code never re-fires just because an unrelated line
  changed. The trade-off, stated explicitly where it applies (see
  `config.env-missing-from-example` above): a change whose effect is felt
  elsewhere in a file this diff never touched is not caught. Catching
  that fully would mean scanning every tracked file at head revision on
  every run — a materially different, heavier tool than the one VISION.md
  §7 describes.
- **Never guesses at an unresolvable value.** A variable, a dynamic
  expression, or an unrecognized method call is always skipped, never
  assumed — a missed finding (false negative), never a fabricated one
  (false positive). Every extractor in `src/Php/` and `src/Diff/` takes
  this posture identically.

If you hit a real gap not covered above or in a specific rule's own
documentation, please open an issue — see CONTRIBUTING.md.

## Prior art / related projects

TicoScope is not the first tool to look at Laravel migrations for risky
patterns, and it doesn't try to be the best *pure* migration linter — it
leads with the multi-signal report (migrations, queued jobs, config/env,
Composer dependencies, unified severity model) and the CI-gate-first
design (`--fail-on`, `--format=json`), not with migration detection alone.

- **Migration-risk linters** (the closest overlap, and the one part of
  this project's scope that's least novel):
  [aofdafaw/Laravel-migration-guard](https://github.com/aofdafaw/Laravel-migration-guard),
  [malikad778/laravel-migration-guard](https://github.com/malikad778/laravel-migration-guard),
  [shashankafre/migration-guard](https://packagist.org/packages/shashankafre/migration-guard),
  [nkmryu/laravel-strong-migrations](https://packagist.org/packages/nkmryu/laravel-strong-migrations),
  [catidegla/safe-migrations](https://root.packagist.org/packages/catidegla/safe-migrations),
  [roslov/laravel-migration-checker](https://github.com/roslov/laravel-migration-checker) —
  explicitly modeled on Ruby's `strong_migrations`. If all you want is
  deep migration-only linting, one of these may suit you better; TicoScope
  treats migrations as one rule category among several, not the product.
- **Schema-drift detection** (a related but distinct problem — drift
  between migration files and live database state, not diff-based risk
  classification):
  [MigrAlign](https://laravel-news.com/detect-and-resolve-laravel-schema-drift-with-migralign),
  [laravel-schema-sentinel](https://github.com/ahtesham-clcbws/laravel-schema-sentinel),
  [erimeilis/laravel-migrations-drift](https://root.packagist.org/packages/erimeilis/laravel-migrations-drift).
- **The closest architectural precedent**:
  [Roave/BackwardCompatibilityCheck](https://github.com/Roave/BackwardCompatibilityCheck) —
  diffs two git revisions of a PHP codebase and classifies API breaks by
  severity, for library backward compatibility rather than application
  deployment risk. Proof the "diff two refs, run rules, fail CI" pattern
  works; not itself a competing tool.
- **Not the same category at all**: Laravel Pulse, Telescope, Horizon, and
  Nightwatch are runtime observability — they describe a *running*
  application, with no concept of a git revision. Forge, Envoyer, and
  Laravel Cloud execute deployments; they don't analyze what's in one.
  TicoScope never grows into either of those — see VISION.md §6.

## Testing

```bash
composer install
vendor/bin/pest
```

CI runs this same suite against every combination of PHP (8.2, 8.3, 8.4)
and Laravel (11, 12) this package declares support for, plus a dedicated
`--prefer-lowest` job against the oldest declared combination — see
`.github/workflows/tests.yml`.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) — adding a new `Rule` is the
primary way this project grows, and it's a deliberately small, reviewable
kind of PR.

## License

MIT. See [LICENSE](LICENSE).
