# Changelog

All notable changes to this project are documented in this file. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.2.0] — Unreleased

### Added

- Laravel 13 support (`illuminate/console`/`illuminate/support`
  `^11.0 || ^12.0 || ^13.0`, `orchestra/testbench` adds `^11.0`,
  `pestphp/pest` adds `^4.4`, `pestphp/pest-plugin-laravel` adds `^4.1`).
  Laravel 13 requires PHP 8.3 or 8.4 — PHP 8.2 remains valid only
  alongside Laravel 11 or 12, matching upstream's own PHP floor for
  Laravel 13. Laravel 11 and 12 support is unchanged. CI now runs every
  valid PHP × Laravel combination (PHP 8.2 × Laravel 13 excluded, since
  that combination cannot resolve) plus the existing `--prefer-lowest`
  job.

## [0.1.0] — 2026-09-26

Initial release. Fifteen deterministic analysis rules across Migration,
Queue Job, Composer, and Config/env categories; a real Git diff engine
with merge-base-relative comparison and rename detection; a
`ConsoleReporter` and a versioned `JsonReporter` (`schema_version: "1"`);
`--fail-on` severity gating and `--format={console|json}` output selection
for `php artisan ticoscope:check`.

See README.md's "Rules" section for the complete, itemized rule list and
CLI behavior.
