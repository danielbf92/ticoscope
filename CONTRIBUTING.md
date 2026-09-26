# Contributing to TicoScope

TicoScope is a deterministic, diff-based static analyzer for Laravel
applications. Every finding must be traceable to a rule a user can read
and reason about — see [VISION.md](VISION.md) for the full problem
statement, scope, and non-goals before proposing anything that isn't a
new `Rule`.

## The primary way this project grows: add a rule

Per VISION.md §7, a `Rule` is a small, self-contained interface: it
receives a `Diff` and returns zero or more `Finding`s. Rules don't know
about each other, don't know about the reporters, and share no mutable
state. That makes "add a rule + its fixtures" a much smaller, more
reviewable PR than most Laravel package contributions — this is the
contribution surface this project is actually built around.

### 1. Confirm it belongs in scope

Read VISION.md §5 (v0.1 scope) and §6 (explicit non-goals) first. A rule
proposal should answer, concretely: what operationally significant risk
does this catch, and what's the exact pattern that indicates it? "This
seems risky" is not a rule; "a migration calling `dropColumn()`" is.

### 2. Reuse an existing primitive before building a new one

Before writing a new parser, check whether an existing one already
extracts what you need:

- `TicoScope\Php\SchemaCallExtractor` — Blueprint fluent-call chains in a
  migration (used by all four Migration rules).
- `TicoScope\Php\FqcnExtractor` / `PublicPropertyExtractor` — a PHP
  class's identity and public properties (used by the Queue rules).
- `TicoScope\Php\EnvCallExtractor` / `EnvExampleParser` — `env()` call
  sites and `.env`-format keys (used by the Config rules).
- `TicoScope\Php\ComposerLockParser` — `composer.lock`'s resolved package
  versions (used by the Composer rules).
- `TicoScope\Diff\AddedLineExtractor` — the newly-added source from a
  `ChangedFile`'s patch, for rules that must only fire on what a diff
  actually introduced, not pre-existing code.

Every one of these is a deliberately narrow, tokenizer- or format-specific
extractor, not a general parsing framework. Two existing rules needing the
exact same list or lookup table (e.g. `BlueprintColumnMethods`, shared by
`NonNullableWithoutDefaultRule` and `ColumnTypeChangedRule`) is a good
reason to extract a shared primitive; one rule needing something close to
an existing one but not identical is usually a reason to write a small,
separate primitive instead of retrofitting a proven one.

If nothing fits, write the smallest new extractor that answers your rule's
question — see the existing `src/Php/` and `src/Diff/` classes for the
shape: a single `extract()`/`parse()` method, a narrow, explicitly-stated
recognized-pattern (never a guess at an unresolvable value), and its own
dedicated unit test file under `tests/Unit/Php/` or `tests/Unit/Diff/`.

### 3. Freeze the finding contract before writing the rule

Every shipped rule has an exact, deliberate answer to each of these,
decided *before* implementation, not discovered while coding:

- **`id()`** — `{category}.{object}-{state}`, e.g. `migration.column-dropped`,
  `queue.job-fqcn-changed`. Once shipped, a rule id is part of the public
  API (see the JSON schema policy in README.md) — changing one later is a
  breaking change.
- **Severity** — `Critical` only for something unconditionally destructive
  regardless of context (e.g. dropping a column). `Warning` for a real but
  conditional or non-destructive operational risk. `Info` for something
  worth a look that frequently isn't actually a problem (currently only
  `composer.package-major-bump`). State *why* a given tier was chosen, not
  just which one.
- **Message** — specific and concrete: exact names, exact old/new values,
  never a vague category label. State plainly what TicoScope does and
  doesn't know (see `ColumnTypeChangedRule` for the clearest example of a
  message that's honest about a real limitation, not overclaiming
  certainty).
- **`reasonCode`** — a stable, machine-readable identifier for *what*
  triggered the finding (usually the column/variable/package/class name),
  not a copy of the message.

### 4. Write the rule

- Constructor-inject `GitDiffReader` (if you need to read file content at a
  revision) plus default-constructed collaborators (classifier, extractor),
  matching every existing rule's shape — see any file under `src/Rules/`.
- Gate on `FileClassifier`/`FileCategory` where the risk is genuinely
  file-category-scoped; a direct, explicit path check is more honest when
  it isn't (see `ComposerPackageMajorBumpRule` for why it bypasses
  `FileCategory::Composer`, which also matches `composer.json`).
- Never guess at an unresolvable value (a variable, a dynamic expression) —
  skip silently. A missed finding (false negative) is always preferred over
  a fabricated one (false positive) in this project.

### 5. Test against real git repositories, not a hand-rolled diff format

Per VISION.md §9, every rule's test suite builds a real temporary git
repository via `TicoScope\Tests\Support\TemporaryGitRepository`
(`writeFile`, `commit`, `checkoutNewBranch`, `deleteFile`, `renameFile`)
and asserts on the `Finding`s a real `GitDiffReader` comparison produces —
never a mocked or hand-constructed `Diff`. Follow the test matrix shape
every existing `tests/Feature/Rules/**/*Test.php` file already uses:

- A positive case with the exact frozen message/`reasonCode` asserted
  verbatim.
- A negative case for each condition that should suppress the finding.
- A malformed-content case proving the rule degrades to "no finding,"
  never a thrown exception.
- An unexpected-Git-failure case proving a real infrastructure error
  (e.g. `GitCommandFailedException`) is never swallowed into "no finding."
- A "fires alongside" case with at least one sibling rule, proving the new
  rule composes correctly rather than interfering with existing ones.

### 6. Register it

Add the new `Rule` to `TicoScopeServiceProvider::register()`, and update
README.md's rule list and rule count.

## Everything else

Bug fixes, documentation corrections, and test improvements to existing
rules are welcome as smaller, standalone PRs — the same rigor applies
(real git fixtures, no behavior change without an explanation of why).

Please open an issue before a large change (a new reporter, a new
extractor shared across categories, anything touching `GitDiffReader` or
the `Rule`/`Finding`/`Reporter` interfaces) so the design can be discussed
before code is written.
