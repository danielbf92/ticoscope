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

## Current state (Milestone 1)

This is scaffolding only:

- A Composer-installable Laravel package, with a service provider and
  auto-discovery.
- A minimal `ticoscope:check` Artisan command that accepts `--base` but does
  not yet perform any analysis.
- The core domain vocabulary the rest of the tool will be built on:
  `ChangedFile`, `Diff`, `Finding`, `Severity`, `Rule`, `Analyzer`, `Reporter`.

No analysis rules, git diffing, config handling, or reporters are implemented
yet.

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

Currently this only confirms the command is wired up; it does not analyze
anything yet.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT. See [LICENSE](LICENSE).
