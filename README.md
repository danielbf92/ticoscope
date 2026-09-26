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

## Current state (Milestone 6 + routing fast-follow)

- A Composer-installable Laravel package, with a service provider and
  auto-discovery.
- A real Git diff engine (`GitDiffReader`): merge-base-relative comparison
  between a base and head revision, with rename detection, per-file patch
  capture, and reading a file's content at an arbitrary revision.
- Laravel-aware file classification (`FileClassifier`): migrations, config
  files, routes, queued Jobs, `.env.example`, and Composer files are
  recognized by path, either by a file's current path or (for rules that
  need it) its pre-change path.
- Seven real analysis rules, closing out VISION.md's entire Queue Job
  category:
  - `config.env-without-default` flags a newly introduced `env()` call in a
    config file with no usable fallback (including an explicit `null`
    fallback) — the `config:cache` hazard VISION.md calls out as the
    strongest single feature to build first. It only looks at lines the
    diff actually added, so an existing, untouched `env()` call never
    re-fires just because an unrelated line in the same file changed.
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
- `ticoscope:check` runs the real diff and all seven rules, prints the
  changed-file list (with classification), and renders findings through a
  real `ConsoleReporter` — grouped by severity (critical, then warning, then
  info), most severe first.
- `--fail-on={info|warning|critical}` gates the command's exit code on
  finding severity, so `ticoscope:check` can be used as a real CI check.
- The core domain vocabulary the rest of the tool is built on: `ChangedFile`,
  `Diff`, `Finding`, `Severity`, `Rule`, `Analyzer`, `Reporter`.

Not yet implemented: migration rules, Composer rules, the `.env.example`
cross-reference half of the config/env rule, route rules, `JsonReporter`,
`--format`, CI/GitHub Action integration.

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
