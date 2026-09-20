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

## Current state (Milestone 3)

- A Composer-installable Laravel package, with a service provider and
  auto-discovery.
- A real Git diff engine (`GitDiffReader`): merge-base-relative comparison
  between a base and head revision, with rename detection and per-file patch
  capture.
- Laravel-aware file classification (`FileClassifier`): migrations, config
  files, routes, queued Jobs, `.env.example`, and Composer files are
  recognized by path.
- The first real analysis rule: `config.env-without-default` flags a newly
  introduced `env()` call in a config file with no usable fallback (including
  an explicit `null` fallback) — the `config:cache` hazard VISION.md calls out
  as the strongest single feature to build first. It only looks at lines the
  diff actually added, so an existing, untouched `env()` call never re-fires
  just because an unrelated line in the same file changed.
- `ticoscope:check` runs the real diff and the real rule, and prints the
  changed-file list (with classification) and any findings. This is still a
  minimal, temporary output format — not the final severity-grouped
  `ConsoleReporter` from VISION.md's scope.
- The core domain vocabulary the rest of the tool is built on: `ChangedFile`,
  `Diff`, `Finding`, `Severity`, `Rule`, `Analyzer`, `Reporter`.

Not yet implemented: migration/queue/composer rules, the `.env.example`
cross-reference half of the config/env rule, `ConsoleReporter`/`JsonReporter`,
`--fail-on` severity gating.

## Installation

```bash
composer require ticoscope/laravel
```

Not yet published to Packagist — install from a local path repository or VCS
reference until a first tagged release exists.

## Usage

```bash
php artisan ticoscope:check --base=main
```

```
TicoScope
Comparing main → feature

3 files changed

ADDED     app/Jobs/SyncInventory.php [queue-job]
RENAMED   app/Old.php → app/Renamed.php [unclassified]
MODIFIED  config/services.php [config]

Findings (1)
  WARNING  [config.env-without-default] config/services.php
    New env() call for REPORTING_ENDPOINT has no fallback. If the variable is
    missing when configuration is cached, this config value may resolve to
    null.
```

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT. See [LICENSE](LICENSE).
