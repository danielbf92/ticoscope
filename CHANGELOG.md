# Changelog

All notable changes to this project are documented in this file. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] — 2026-09-26

Initial release. Fifteen deterministic analysis rules across Migration,
Queue Job, Composer, and Config/env categories; a real Git diff engine
with merge-base-relative comparison and rename detection; a
`ConsoleReporter` and a versioned `JsonReporter` (`schema_version: "1"`);
`--fail-on` severity gating and `--format={console|json}` output selection
for `php artisan ticoscope:check`.

See README.md's "Current state" section for the complete, itemized rule
list and CLI behavior.
