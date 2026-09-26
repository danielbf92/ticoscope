<?php

namespace TicoScope\Rules\Config;

use TicoScope\Diff\AddedLineExtractor;
use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Diff\Diff;
use TicoScope\Findings\Finding;
use TicoScope\Findings\Severity;
use TicoScope\Git\GitDiffReader;
use TicoScope\Php\EnvCallExtractor;
use TicoScope\Php\EnvExampleParser;
use TicoScope\Rules\Rule;

/**
 * Flags a newly-added env() call, anywhere in changed application/config
 * PHP code (not just config/*.php — VISION's own wording is broader than
 * EnvWithoutDefaultRule's config-file-only scope), whose referenced
 * variable is absent from .env.example's current (head-revision) declared
 * keys.
 *
 * Deliberately diff-scoped, not a full-codebase reconciliation: a key
 * silently removed from .env.example while the code that reads it goes
 * untouched is not caught here — that would require enumerating and
 * scanning every tracked file at head revision, a materially bigger
 * primitive than a diff of what changed, and a direct departure from
 * VISION §7's diff-first architecture principle. See README.md for this
 * limitation stated plainly.
 *
 * tests/ is deliberately excluded: test code legitimately references
 * variables (fixture/env-faking constants, testing-only toggles) that
 * should never appear in .env.example, and flagging those would be a
 * false positive this project's false-negative-over-false-positive
 * posture argues against.
 */
final class EnvVarMissingFromExampleRule implements Rule
{
    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly AddedLineExtractor $addedLineExtractor = new AddedLineExtractor(),
        private readonly EnvCallExtractor $envCallExtractor = new EnvCallExtractor(),
        private readonly EnvExampleParser $envExampleParser = new EnvExampleParser(),
    ) {
    }

    public function id(): string
    {
        return 'config.env-missing-from-example';
    }

    /**
     * @return Finding[]
     */
    public function analyze(Diff $diff): array
    {
        $exampleSource = $this->gitDiffReader->readFile($diff->headRevision, '.env.example');

        if ($exampleSource === null) {
            return [];
        }

        $declaredKeys = $this->envExampleParser->parse($exampleSource);

        $findings = [];

        foreach ($diff->changedFiles as $file) {
            if ($file->changeType === ChangeType::Deleted) {
                continue;
            }

            if (! str_ends_with($file->path, '.php')) {
                continue;
            }

            if (str_starts_with($file->path, 'tests/')) {
                continue;
            }

            foreach ($this->addedLineExtractor->extract($file) as $addedRun) {
                foreach ($this->envCallExtractor->extract($addedRun) as $variable) {
                    if (! in_array($variable, $declaredKeys, true)) {
                        $findings[] = $this->toFinding($file, $variable);
                    }
                }
            }
        }

        return $findings;
    }

    private function toFinding(ChangedFile $file, string $variable): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Warning,
            file: $file,
            message: sprintf(
                'New env() call references "%s", which is not declared in .env.example. A fresh checkout, '.
                'CI runner, or new team member won\'t know this variable is expected unless it\'s documented '.
                'there.',
                $variable,
            ),
            reasonCode: $variable,
        );
    }
}
