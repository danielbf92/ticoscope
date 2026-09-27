# TicoScope

**Deterministic, diff-based pre-deployment risk analysis for Laravel.**

[![Packagist Version](https://img.shields.io/packagist/v/ticoscope/laravel)](https://packagist.org/packages/ticoscope/laravel)
[![Packagist Downloads](https://img.shields.io/packagist/dt/ticoscope/laravel)](https://packagist.org/packages/ticoscope/laravel)
[![Tests](https://github.com/danielbf92/ticoscope/actions/workflows/tests.yml/badge.svg)](https://github.com/danielbf92/ticoscope/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/ticoscope/laravel)](https://packagist.org/packages/ticoscope/laravel)
![Laravel 11 | 12](https://img.shields.io/badge/laravel-11%20%7C%2012-FF2D20)
[![License](https://img.shields.io/packagist/l/ticoscope/laravel)](LICENSE)
[![GitHub Release](https://img.shields.io/github/v/release/danielbf92/ticoscope)](https://github.com/danielbf92/ticoscope/releases)

`git diff` tells you which files changed. It doesn't tell you which of
those changes are operationally significant: a migration that drops a
column, a config key that will silently resolve to `null` in production,
a queued job whose payload will break for jobs already sitting on the
queue. TicoScope reads the git diff between two revisions and flags
exactly that kind of risk — deterministically, with no AI guessing and no
live database or queue connection required — so your CI pipeline can gate
a deploy on it the same way it already gates on a failing test.

## Requirements

- PHP 8.2, 8.3, or 8.4
- Laravel (`illuminate/support`/`illuminate/console`) 11 or 12

## Installation

```bash
composer require --dev ticoscope/laravel
```

It's a development-time analysis tool, not a runtime dependency — install
it as `--dev`.

## Quick start

```bash
php artisan ticoscope:check --base=main --fail-on=warning
```

```
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

Exit code is non-zero as soon as any finding meets or exceeds the
`--fail-on` threshold — drop it straight into an existing CI job.

## How it works

TicoScope computes a merge-base-relative diff (`git diff base...head`,
the same semantics `git diff --raw` and rename detection give you), runs
a set of independent, deterministic rules against it, and reports every
finding with a severity, the responsible rule, and a specific,
human-readable reason — never a generic "this changed" warning. Every
rule is pure pattern-matching over the diff and file content: no code is
executed, no database or queue connection is made, and no finding is ever
based on anything other than a rule a human can read and reason about.
See [VISION.md](VISION.md) for the full design philosophy, architecture
principles, and scope.

## Rules

Fifteen rules across four categories. Severity is `Critical` (destructive
regardless of context), `Warning` (a real but conditional operational
risk), or `Info` (worth a look, often not actually a problem).

### Config / Environment

| Rule ID | Severity | Catches |
|---|---|---|
| `config.env-without-default` | Warning | A newly-added `env()` call in a config file with no usable fallback — the classic `config:cache` footgun. |
| `config.env-missing-from-example` | Warning | A newly-added `env()` call anywhere in application/config code whose variable isn't declared in `.env.example`. |

### Queue Jobs

| Rule ID | Severity | Catches |
|---|---|---|
| `queue.job-fqcn-changed` | Warning | A queued Job class's namespace or class name changed — already-queued jobs referencing the old name may fail to deserialize. |
| `queue.job-class-removed` | Warning | A queued Job class was deleted outright. |
| `queue.job-property-removed` | Warning | A public property removed from a Job class — an already-queued payload unserializes it as an undeclared dynamic property. |
| `queue.job-property-retyped` | Warning | A public property's declared type changed — throws a real `TypeError` on unserialize if the queued value doesn't match. |
| `queue.job-connection-changed` | Warning | A Job's declared `$connection` literal changed — dispatched jobs may route to an unmonitored connection. |
| `queue.job-queue-changed` | Warning | A Job's declared `$queue` literal changed, for the same reason. |

### Migrations

| Rule ID | Severity | Catches |
|---|---|---|
| `migration.column-dropped` | Critical | A migration drops a column outright — destructive and typically irreversible. |
| `migration.table-dropped` | Critical | A migration drops a table outright, for the same reason. |
| `migration.column-renamed` | Warning | A migration renames a column — application code and queries referencing the old name break immediately. |
| `migration.non-nullable-without-default` | Warning | A column added to an *existing* table with no `->nullable()` and no `->default()` — may fail outright if the table already has rows. |
| `migration.column-type-changed` | Warning | A column redefined via `->change()` — TicoScope can't see the previous definition, so this flags the redefinition itself as worth checking, not a confirmed truncation. |

### Composer

| Rule ID | Severity | Catches |
|---|---|---|
| `composer.package-major-bump` | Info | A dependency's resolved major version changed in `composer.lock` (direction-agnostic — a downgrade counts too). |
| `composer.package-removed` | Warning | A dependency present in the old `composer.lock` is entirely absent from the new one. |

## CLI reference

```bash
php artisan ticoscope:check --base=main [--fail-on=LEVEL] [--format=console|json]
```

- **`--fail-on={info|warning|critical}`** — optional, lowercase-only.
  Omit it to always exit successfully regardless of findings; set it to
  gate CI on a minimum severity. The command exits non-zero as soon as
  any finding meets or exceeds that threshold.
- **`--format=json`** — machine-readable output for CI, a PR-comment
  bot, or any other integration. stdout carries *only* the JSON
  document — safe to pipe straight into `jq` or any JSON consumer. Every
  failure path (an invalid option, a Git error) also emits valid JSON
  under `--format=json`, with the same exit code as console mode.

```bash
php artisan ticoscope:check --base=main --format=json --fail-on=critical | jq '.findings'
```

```json
{
    "schema_version": "1",
    "summary": { "total": 2, "critical": 0, "warning": 2, "info": 0 },
    "findings": [
        {
            "rule_id": "config.env-without-default",
            "severity": "warning",
            "file": "config/services.php",
            "message": "New env() call for REPORTING_ENDPOINT has no fallback. If the variable is missing when configuration is cached, this config value may resolve to null.",
            "reason_code": "REPORTING_ENDPOINT"
        }
    ]
}
```

## JSON output & compatibility

`schema_version` is a stated compatibility promise, not an incidental
field:

- **Within `schema_version: "1"`, changes are additive only** — a new
  field may be added; no existing field is ever renamed, removed, or
  repurposed.
- **A breaking change increments `schema_version`** to `"2"` — it never
  silently changes what `"1"` means. A consumer that ignores unknown
  fields is safe across additive changes without ever branching on
  `schema_version`.
- **New rule ids may be added at any time**; an existing rule id, once
  shipped, is not renamed.
- **`reason_code` is always present** as a key, `null` when a rule has
  none to give — never conditionally omitted.

## Known Limitations

TicoScope is entirely static analysis of git diffs and file content — it
never executes code, never runs a migration, never introspects a live
database or queue. That trade-off (speed, safety, zero-config CI use)
shapes every rule the same few ways:

- **Pattern-based, not size/shape-aware** — a rule recognizes a code
  pattern, not the actual size of a table or the real impact of a
  dependency bump.
- **Diff-scoped, not a full-codebase audit** — most rules only look at
  what a diff actually added, so untouched code never re-fires; the
  trade-off is that an effect felt elsewhere in an untouched file isn't
  caught.
- **Never guesses at an unresolvable value** — a variable or dynamic
  expression is always skipped, never assumed. A missed finding is always
  preferred over a fabricated one.

See each rule's own entry above for anything more specific, and
[VISION.md](VISION.md) §4 for the full reasoning.

## Compatibility & CI

CI runs the full suite against PHP 8.2/8.3/8.4 × Laravel 11/12, plus a
dedicated `--prefer-lowest` job against the oldest declared combination —
see [`.github/workflows/tests.yml`](.github/workflows/tests.yml).

## Prior art / related projects

TicoScope leads with a multi-signal report (migrations, queued jobs,
config/env, Composer dependencies, one severity model) and a
CI-gate-first design, not with migration detection alone:

- **Migration-risk linters** (the closest overlap): [aofdafaw/Laravel-migration-guard](https://github.com/aofdafaw/Laravel-migration-guard), [malikad778/laravel-migration-guard](https://github.com/malikad778/laravel-migration-guard), [shashankafre/migration-guard](https://packagist.org/packages/shashankafre/migration-guard), [nkmryu/laravel-strong-migrations](https://packagist.org/packages/nkmryu/laravel-strong-migrations), [catidegla/safe-migrations](https://root.packagist.org/packages/catidegla/safe-migrations), [roslov/laravel-migration-checker](https://github.com/roslov/laravel-migration-checker) — modeled on Ruby's `strong_migrations`. If all you want is deep migration-only linting, one of these may suit you better.
- **Schema-drift detection** (a related, distinct problem): [MigrAlign](https://laravel-news.com/detect-and-resolve-laravel-schema-drift-with-migralign), [laravel-schema-sentinel](https://github.com/ahtesham-clcbws/laravel-schema-sentinel), [erimeilis/laravel-migrations-drift](https://root.packagist.org/packages/erimeilis/laravel-migrations-drift).
- **The closest architectural precedent**: [Roave/BackwardCompatibilityCheck](https://github.com/Roave/BackwardCompatibilityCheck) — diffs two git revisions and classifies API breaks by severity, for library backward compatibility rather than application deployment risk.
- **Not the same category**: Laravel Pulse, Telescope, Horizon, and Nightwatch are runtime observability, not pre-deploy analysis. Forge, Envoyer, and Laravel Cloud execute deployments; they don't analyze what's in one.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) — adding a new `Rule` is the
primary way this project grows, and it's a deliberately small,
reviewable kind of PR.

## License

MIT. See [LICENSE](LICENSE).
