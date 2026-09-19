# VISION.md — TicoScope

Status: draft v0.1 vision, not yet implemented.
Purpose of this document: define the problem, scope, and architecture before any package code is written.

## 1. Problem We're Solving

Laravel gives you excellent tools for writing an application and mediocre tools for knowing what a *release* of that application actually changes. `git diff main...HEAD` tells you which files changed. It does not tell you which of those changes are operationally significant: a migration that will lock a large table, a config key that got renamed and will now silently resolve to `null` in production, a queued job whose serialized payload will break for jobs already sitting on the queue, a new required environment variable nobody added to the production `.env`.

Today that knowledge lives in the deploying engineer's head, or in a manually-maintained deploy checklist, or it doesn't exist at all until something breaks after deploy and someone reconstructs it from an incident. Laravel's own conventions — migrations, config files, queued jobs, `.env.example`, `composer.lock` — are exactly the kind of structured, version-controlled surface that a deterministic static analyzer can reason about *before* the release happens. Nothing in the ecosystem currently reads a Laravel application's git history end-to-end and answers, specifically, "what should I know before I deploy this?"

That is the gap. The tool is a diff-based, Laravel-aware pre-deployment risk report, run from the command line, that a human or a CI pipeline can act on before code reaches production.

## 2. Target Developer

- A Laravel engineer or small team shipping to production regularly (daily or weekly, not once a quarter), where "what changed since last deploy" is a real question with a real cost of getting wrong.
- Someone who already has a CI pipeline (GitHub Actions, GitLab CI, etc.) and wants an automated gate, not another dashboard to check manually.
- Someone maintaining a Laravel application with a nontrivial database (tables with real row counts, not a toy schema), background job processing, and more than one deployment environment — i.e., someone who has been burned before by "the migration was fine locally" or "we forgot to restart the workers."
- Explicitly *not* the target: a hobbyist shipping a single-server side project with no CI, no queue workers, and a database small enough that any migration finishes in milliseconds. The tool has to earn its keep against the cost of running it, and for that user it mostly won't.

## 3. Product Proposition

`php artisan ticoscope:check --base=main` (or the equivalent CI invocation) inspects everything that changed between a base revision and the current one, classifies each change against a set of deterministic, Laravel-specific rules, and produces a report — human-readable in the terminal, machine-readable as JSON — with each finding assigned a severity (`info`, `warning`, `critical`). CI can be configured to fail the build when findings reach a configured severity threshold. Nothing here requires the tool to run against a live database, a live queue, or a live anything — it operates entirely on the git diff and the static structure of the code, which is what makes it fast, deterministic, and safe to run in CI without credentials.

## 4. What Makes This Different — and Where the Idea Is Genuinely Weak

This section is the honest part. Some of what's below is a real gap; some of it overlaps with existing tools more than the project description admits, and one piece of it is close to redundant with standard practice. Worth confronting before writing code.

**Where the differentiation is real.** Laravel Pulse, Telescope, Horizon, and the newer Nightwatch are all runtime observability: they tell you what a *running* application is doing right now (slow queries, failed jobs, queue depth, exceptions). They have no concept of a git revision and nothing to say about code that hasn't been deployed yet. Forge, Envoyer, and Laravel Cloud are deployment *execution* tools — they run your deploy script, they don't analyze what's in it. Larastan, Rector, and Pint analyze the current state of your code for type safety, upgrade opportunities, and style; none of them diff two revisions and reason about deployment-time operational consequences. So the category — "deterministic static analysis of a git diff, expressed in Laravel-domain terms, producing a severity-scored pre-deploy report" — is not occupied by any of the tools a Laravel developer would already have installed. That's a legitimate niche.

The strongest single feature in the proposed scope is the config-caching risk. `php artisan config:cache` bakes `env()` calls into a compiled file at deploy time; if a new config key calls `env()` and that variable isn't set in production, or if `env()` is called anywhere outside a config file, the failure is silent and specific to production. This is a well-known but tooling-poor Laravel footgun, and a rule that diffs `config/*.php` and `.env.example` against each other to catch it is a genuinely useful, hard-to-find-elsewhere feature. Build this first; it's the best evidence the whole idea is worth pursuing.

**Where it overlaps more than the pitch admits.** "Detect new or modified database migrations and flag potentially risky operations" is not a gap — it's a small, somewhat crowded corner of the Laravel package ecosystem already. A search turns up multiple packages doing exactly this, explicitly modeled on Ruby's `strong_migrations`: `laravel-migration-guard` (at least three near-identical forks under different authors — aofdafaw, malikad778, jamil — which itself is a signal that this is a well-known, low-effort-to-clone idea, not a defensible feature), `shashankafre/migration-guard`, `nkmryu/laravel-strong-migrations`, `catidegla/safe-migrations`, and `roslov/laravel-migration-checker`. There's a second, adjacent cluster — `laravel-schema-sentinel`, `laravel-migrations-drift`, and the "MigrAlign" package covered on Laravel News — that solves a related but distinct problem (drift between migration files and live database state, not risk classification of a diff). None of these does the multi-signal combination this project proposes, and code quality/adoption on most of the migration-guard forks looks thin, so there's room to do it better — but "detect risky migrations" cannot be the headline pitch. It has to be positioned as one rule category inside a broader report, not the product.

There's also a real, proven architectural precedent worth citing rather than ignoring: `roave/backward-compatibility-check` has done "diff two git revisions of a PHP codebase, run static rules against the diff, classify breaks by severity, fail CI" for years, just for a different question (public API backward compatibility in libraries, not deployment risk in applications). That's good news for the architecture — this pattern is proven — but it also means "diff two git refs and run rules against the result" is not itself a novel mechanism. The novelty has to be in the Laravel-specific rule set and the operational framing, not the diffing mechanism.

**Where a feature is weak as specified.** "Detect changes to queued Jobs and recommend worker restart when appropriate" is the softest item in the scope. Restarting queue workers after every deploy is not a conditional best practice — it's the unconditional standard: Forge's default deploy script and Taylor Otwell's own guidance both run `php artisan queue:restart` on *every* deploy regardless of what changed, because PHP-FPM/worker processes cache autoloaded classes in memory and any code change can be affected. A rule that says "you changed a Job, so restart your workers" adds little for a team that already restarts workers on every deploy (which is most teams using Forge or Envoyer), and gives false comfort to a team that doesn't (silence on a deploy with no Job changes doesn't mean restart is unnecessary — it always is, if the app code changed at all). The rule as specified is answering a question nobody who deploys correctly is asking. The version of this rule that's actually worth building is narrower and sharper: detect changes that break jobs *already sitting on the queue* at deploy time — a Job class renamed or moved (breaks unserialization of already-queued jobs pointing at the old class name), a public property removed or retyped on a Job class, or a change to the queue connection/driver a class is dispatched through. Those are real, specific, silent failure modes that generic "restart your workers" advice does not address, and no existing tool checks for them. Reframe the feature around that, or cut it.

"Detect changes to routes" is listed in the project description's example concepts implicitly (via general Laravel-change classification) but isn't well-specified as a risk rule yet. A route file diff is trivial to produce with `git diff`; the risk is not that a route changed, it's that a route lost a middleware it depended on (auth, throttling, a policy gate) or that a route customers hit directly (webhooks, API endpoints with external callers) was renamed or removed. Ship this only once there's a concrete rule for what "risky" means here — otherwise it's noise that erodes trust in the rest of the report.

**A structural limitation to be upfront about.** Everything in v0.1 is static analysis of file contents and diffs — it does not execute migrations against a real database (not even `--pretend`), does not introspect an actual schema, and does not run the code it's analyzing. That's a deliberate, correct trade-off for speed, safety, and zero-config CI use, but it means the "risky migration" rules will necessarily be pattern-based (looking for `dropColumn`, `renameColumn`, non-nullable columns added without a default, etc.) rather than grounded in the actual size or shape of the production table. Pattern-based rules produce both false positives (a `dropColumn` on a table with ten rows in a side project is not risky) and false negatives (a seemingly benign `addColumn` can still lock a huge table on certain MySQL/MariaDB versions and storage engines). This should be stated as a known limitation in the README, not discovered by users the hard way. Composer dependency changes are the mildest addition to the scope: `git diff composer.lock` is one command a developer can already run, and Dependabot/Renovate already surface dependency changes in PR form. The value-add here is narrow — mainly flagging major-version bumps or removed packages that other rules (e.g., a package a Job or config file depends on) can then cross-reference — and it should be scoped modestly rather than treated as a headline feature.

## 5. v0.1 Scope

- Compute the changed-file set between a base revision (default `main`, overridable) and the current working tree/branch using Git, producing a typed `ChangedFile` for each entry (path, change type: added/modified/deleted/renamed, raw diff hunk).
- Classify each `ChangedFile` into a Laravel-relevant category: migration, config file, route file, queued Job class, `.env.example`, `composer.json`/`composer.lock`, or "unclassified" (still reported, lowest severity, for completeness).
- Migration rules: flag new/modified migration files performing operations with known production risk — dropping a column or table, renaming a column, adding a non-nullable column without a default, changing a column type in a way that can truncate data — each flagged with the specific reason, not just "this migration changed."
- Config/env rules: flag new config keys that call `env()` (a `config:cache` hazard), and flag environment variables referenced in application/config code that are missing from `.env.example`.
- Queue Job rules (narrowed per the critique above): flag renamed/moved Job classes, removed or retyped public properties on Job classes, and changed queue connection/driver assignments — each with an explanation of the already-queued-job risk, not a blanket "restart your workers" message.
- Composer rules: flag major-version bumps and removed dependencies in `composer.lock`, scoped as a lightweight cross-reference signal rather than a full dependency audit.
- `ConsoleReporter`: human-readable terminal output, grouped by severity, suitable for a developer reading it locally before opening a PR.
- `JsonReporter`: machine-readable output for CI consumption, with a stable schema from v0.1 (this is a compatibility promise worth taking seriously — see Open-Source Strategy).
- A configurable severity threshold (`--fail-on=critical`, `--fail-on=warning`) that controls the command's exit code for CI gating.

## 6. Explicit Non-Goals

The project description already excludes these; restating them here as a standing commitment, not just a v0.1 deferral:

- No SaaS product, no hosted dashboard, no accounts, no billing.
- No authentication or multi-tenant concerns of any kind.
- No AI-based or LLM-based analysis of the diff. Every finding must be traceable to a deterministic rule a user can read and reason about; "the model thinks this looks risky" is not an acceptable finding in this tool, ever — not just in v0.1.
- No production monitoring, no APM, no runtime tracing. This is not Pulse, Telescope, Horizon, or Nightwatch, and it should never grow a "watch your app live" feature — that's a different product with different trust and infrastructure requirements.
- No Docker/container/infrastructure management.
- No GitHub App, no Slack integration, no notification delivery of any kind in v0.1. The tool produces a report; what happens with that report (posted to Slack, commented on a PR, etc.) is somebody else's integration to build on top of the JSON output.
- No execution of migrations, no database connection required to run the tool at all. If a future version ever needs one (see §9), that is a deliberate, separately-justified architectural change, not an assumption to build in now.

## 7. Architecture Principles

- **Diff-first, not filesystem-first.** The unit of analysis is always "what changed between two revisions," represented as a `Diff` composed of `ChangedFile` entries. The tool never reasons about the current state of the filesystem in isolation from what changed.
- **Rules are independent and composable.** Each `Rule` receives the `Diff` (or the relevant subset of `ChangedFile`s it declares interest in) and returns zero or more `Finding`s. Rules do not know about each other, do not know about the reporters, and do not share mutable state. This is what makes "propose a new rule" a contribution any user can make without touching the Artisan command or the reporting layer.
- **An `Analyzer` orchestrates, it doesn't analyze.** The Analyzer's job is: build the `Diff`, run every registered `Rule` against it, collect `Finding`s, hand them to the chosen `Reporter`. No rule logic lives in the Analyzer or the console command.
- **Findings are structured data, not strings.** A `Finding` carries a severity, a rule identifier, a file reference, a human-readable message, and ideally a machine-readable reason code. The `ConsoleReporter` and `JsonReporter` are both just different serializations of the same `Finding` collection — adding a third reporter (e.g., a GitHub-annotations format, later) should never require touching rule code.
- **Deterministic and side-effect-free.** Given the same two git revisions, the tool produces the same report every time. No network calls, no database connection, no reliance on the current environment's `.env` beyond reading `.env.example` as a file. This is what makes it safe to run unattended in CI.
- **The Artisan command is a thin adapter.** It parses CLI options, constructs the Analyzer with the configured rules and the requested reporter, and sets the exit code from the severity threshold. Business logic does not live in the command class.

## 8. CLI Experience

Primary entry point:

```
php artisan ticoscope:check --base=main
```

Console output groups findings by severity, most severe first, and names the specific file and rule responsible for each finding rather than a generic warning:

```
TicoScope — comparing main...HEAD (14 files changed)

CRITICAL (1)
  ✗ database/migrations/2026_09_12_add_status_to_orders.php
    [migration.drop-column] Migration drops column "legacy_reference" on table "orders".
    This is a destructive, typically irreversible operation. Confirm a backup/rollback plan exists.

WARNING (2)
  ! config/services.php
    [config.env-without-default] New config key "services.reporting.endpoint" calls env()
    with no fallback. If REPORTING_ENDPOINT is unset in production, config:cache will bake in null.
  ! app/Jobs/SyncInventory.php
    [job.class-renamed] Job class renamed from SyncStock to SyncInventory.
    Any already-queued jobs referencing SyncStock will fail to unserialize after deploy.

INFO (1)
  · composer.lock
    [composer.major-bump] "guzzlehttp/guzzle" bumped 6.x → 7.x.

3 findings (1 critical, 2 warning, 1 info). Failing build: --fail-on=warning was set.
```

Machine-readable output for CI:

```
php artisan ticoscope:check --base=main --format=json --fail-on=critical
```

produces a stable, versioned JSON document (`{"schema_version": "1", "summary": {...}, "findings": [...]}`) intended to be consumed by a CI step, a PR-comment bot, or any other integration — deliberately not something this package builds itself in v0.1.

Exit code is non-zero when findings at or above the `--fail-on` threshold are present, so `ticoscope:check` slots into a CI job as a normal command with a normal pass/fail signal.

## 9. Testing Strategy

- **Pest as the sole test framework**, matching the project's stated preference and the Laravel community's default.
- **Fixture-based git repositories** as the primary testing mechanism: each rule's test suite builds a minimal real git repo (via temporary directories and actual `git` commands, not mocked git output) with a "before" and "after" commit exercising the specific pattern the rule targets, then asserts on the `Finding`s produced. Testing against real git output, not a hand-rolled diff format, is what keeps the test suite honest about what the tool actually does in production use.
- **Golden-file tests for both reporters**: fixed input `Finding` collections rendered through `ConsoleReporter` and `JsonReporter`, asserted against committed expected output. This is what protects the JSON schema stability promise made in §8/§10 — any unintentional schema change breaks a test loudly.
- **Rule-level unit tests are the bulk of coverage**, since rules are the part of the system with actual judgment calls (what counts as "risky") and the most likely place for false positives/negatives to hide. Each rule should have explicit positive and negative fixtures — a case that should fire and a deliberately similar case that should not, to guard against overly broad pattern matching.
- **No live database, no live queue, in any test.** Consistent with the architecture principle that the tool never needs either; if a test setup requires a real database connection, that's a signal the code under test has drifted from the diff-only design.
- Coverage target and mutation testing (e.g., Infection) are worth adopting once the rule set stabilizes, but are not a v0.1 blocker — a broad, fixture-driven test suite matters more early on than a coverage percentage.

## 10. Open-Source Strategy

This has to be positioned honestly against the crowded migration-guard corner of the ecosystem noted in §4, or it will read as "yet another one of those." The pitch on the README and Packagist listing should lead with the multi-signal report and the CI-gating workflow, not with migration detection alone — that's the one piece of this that isn't new.

- **License:** MIT, matching Laravel ecosystem convention and maximizing adoption.
- **Positioning in the README:** an explicit "prior art / related projects" section naming the migration-guard-style packages and the schema-drift packages found during this research, stating plainly what this project does that they don't (multi-signal, unified severity model, CI-gate-first design) and what it doesn't try to do (replace them for deep migration-only linting, if a user only wants that).
- **Versioning discipline for the JSON schema specifically:** because the JSON output is meant for CI and third-party integrations, it needs its own explicit `schema_version` field and a compatibility policy from the very first tagged release — this is the one part of the public API that's expensive to break silently.
- **Rule contribution model:** because `Rule` is a small, self-contained interface (see §7), the contribution surface for the community is "add a rule + its fixtures," which is a much easier and more reviewable PR than most Laravel package contributions. That should be documented explicitly (a `CONTRIBUTING.md` with a "how to add a rule" walkthrough) as the primary way the project wants to grow.
- **Avoid the fate of the migration-guard forks.** Several of the existing packages in this space appear to be near-duplicates of each other with little differentiation and thin adoption. The way to avoid becoming another one of those is sustained maintenance and a genuinely useful default rule set (leading with the config/env-caching rule, which nothing else does), not a large surface area at launch.
- **Packagist name and namespace** should be chosen and reserved early, distinct enough from the existing `laravel-migration-guard` cluster to avoid confusion (this project is not a migration linter, even though it includes migration rules).

## 11. Possible Future Direction (not built now, not designed in detail yet)

Listed to show where the architecture should leave room to grow, without committing to any of it in v0.1:

- A first-party GitHub Action / GitLab CI template that wraps the JSON reporter and posts results as a PR comment or check annotation — a thin consumer of the existing JSON output, not a new analysis surface.
- Additional rule categories: broken/missing model factories relevant to changed migrations, service provider changes that alter boot order, Eloquent relationship changes that could produce N+1 patterns at scale, cache-tag or cache-driver changes.
- A rule-pack concept, letting teams or the community publish additional rule sets (e.g., a "Vapor-specific" or "Laravel Cloud-specific" pack, or a pack tuned for a particular database engine's locking behavior) as separate installable packages built against the same `Rule` interface.
- Optional, explicitly opt-in integration with an actual database connection for teams that want migration rules grounded in real table sizes rather than pattern-only heuristics — a meaningful architectural change from the zero-database design in §7, and one that should only be taken on with a clear justification and its own design doc, not folded in quietly.
- Historical trend reporting (has this repo's severity mix been getting better or worse release over release) — useful, but a second product surface (persistence, likely a dashboard) rather than a natural extension of a stateless CLI tool.
- A documented extension point for third-party or AI-assisted *rule authoring* tooling (something that helps a human write a new deterministic `Rule` faster) is worth keeping open architecturally. This is different from, and should not be confused with, AI-based analysis of the diff itself — the latter remains a non-goal per §6, not a "later" item.

None of the above should influence v0.1 implementation beyond making sure the `Rule`/`Finding`/`Reporter` boundaries in §7 don't quietly foreclose them.

## 12. Prior Art & Related Projects (for context, not endorsement)

Referenced in the critique above; worth keeping linked here as the project's own record of what already exists in this space.

- [Roave/BackwardCompatibilityCheck](https://github.com/Roave/BackwardCompatibilityCheck) — the closest architectural precedent: diffs two git revisions of a PHP codebase and classifies API breaks by severity. Different domain (library BC breaks vs. application deploy risk), same core mechanism.
- [aofdafaw/Laravel-migration-guard](https://github.com/aofdafaw/Laravel-migration-guard), [malikad778/laravel-migration-guard](https://github.com/malikad778/laravel-migration-guard), [shashankafre/migration-guard](https://packagist.org/packages/shashankafre/migration-guard), [nkmryu/laravel-strong-migrations](https://packagist.org/packages/nkmryu/laravel-strong-migrations), [catidegla/safe-migrations](https://root.packagist.org/packages/catidegla/safe-migrations), [roslov/laravel-migration-checker](https://github.com/roslov/laravel-migration-checker) — static analysis of migration files for risky operations, explicitly modeled on Ruby's `strong_migrations`. Direct overlap with the migration-rule portion of this project's scope.
- [MigrAlign / laravel-migralign](https://laravel-news.com/detect-and-resolve-laravel-schema-drift-with-migralign), [laravel-schema-sentinel](https://github.com/ahtesham-clcbws/laravel-schema-sentinel), [erimeilis/laravel-migrations-drift](https://root.packagist.org/packages/erimeilis/laravel-migrations-drift) — adjacent problem: drift between migration files and live database state, not diff-based risk classification.
- Laravel Pulse, Telescope, Horizon, Nightwatch — runtime observability, not pre-deploy analysis. Distinct category, referenced in §4 to justify the "not a monitoring tool" positioning.
- Laravel Forge deployment scripts, Envoyer, Laravel Cloud — deployment execution, not analysis. Referenced in §4 re: `queue:restart` already being standard practice on every deploy.

## 13. Open Questions Before Writing Code

- Exact rule identifiers and JSON schema field names should be settled and written down before the first `Rule` is implemented, since changing them later is a breaking change per the versioning policy in §10.
- Whether "renamed" file detection relies on Git's own rename detection (`git diff -M`) or is reconstructed manually needs a decision now — it affects the `ChangedFile` model in §7.
- The precise definition of "risky" for route-middleware changes (flagged as underspecified in §4) needs to be written as a concrete rule spec before it's added to scope, not left as a vague bullet.

---
*This document defines scope and intent. It should be updated whenever scope changes — treat a stale VISION.md as worse than none.*
