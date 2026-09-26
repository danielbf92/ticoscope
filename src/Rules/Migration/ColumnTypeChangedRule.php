<?php

namespace TicoScope\Rules\Migration;

use TicoScope\Classification\FileCategory;
use TicoScope\Classification\FileClassifier;
use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Diff\Diff;
use TicoScope\Findings\Finding;
use TicoScope\Findings\Severity;
use TicoScope\Git\GitDiffReader;
use TicoScope\Php\BlueprintColumnMethods;
use TicoScope\Php\SchemaCallExtractor;
use TicoScope\Rules\Rule;

/**
 * Flags a column redefined via ->change() on an EXISTING table
 * (Schema::table(), never Schema::create() — there is no "previous
 * definition" to change mid-creation). This is a deliberately honest
 * heuristic: a migration only ever states the NEW definition, never the
 * old one, so this tool cannot know whether a given ->change() actually
 * narrows the column (and would therefore truncate existing data) or
 * widens it (completely safe). Every ->change() on a recognized
 * column-type method is flagged equally, at Warning, with the message
 * saying plainly that the previous type is unknown — see VISION §4's
 * explicit acceptance of pattern-based imprecision for migration rules.
 *
 * Deliberately not attempted: reconstructing the column's real previous
 * type from migration history (a materially bigger primitive than a diff
 * of what changed), or a narrow same-file old-vs-new comparison for the
 * rare case of an unreleased migration's own ->change() being edited again
 * (would make this rule's certainty inconsistent between the common case
 * and that rare one, which is worse for trust than one uniform heuristic).
 */
final class ColumnTypeChangedRule implements Rule
{
    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly FileClassifier $classifier = new FileClassifier(),
        private readonly SchemaCallExtractor $schemaExtractor = new SchemaCallExtractor(),
    ) {
    }

    public function id(): string
    {
        return 'migration.column-type-changed';
    }

    /**
     * @return Finding[]
     */
    public function analyze(Diff $diff): array
    {
        $findings = [];

        foreach ($diff->changedFiles as $file) {
            if ($file->changeType === ChangeType::Deleted) {
                continue;
            }

            if ($this->classifier->classify($file) !== FileCategory::Migration) {
                continue;
            }

            $source = $this->gitDiffReader->readFile($diff->headRevision, $file->path);

            if ($source === null) {
                continue;
            }

            $analysis = $this->schemaExtractor->extract($source);

            if ($analysis === null) {
                continue;
            }

            foreach ($analysis->tableOperations as $operation) {
                if ($operation->isNewTable) {
                    continue;
                }

                foreach ($operation->statements as $chain) {
                    $column = $this->changedColumn($chain);

                    if ($column !== null) {
                        $findings[] = $this->toFinding($file, $operation->table, $column);
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<array{method: string, args: list<string|null|list<string|null>>}> $chain
     */
    private function changedColumn(array $chain): ?string
    {
        if ($chain === []) {
            return null;
        }

        $hasChange = false;

        foreach ($chain as $step) {
            if ($step['method'] === 'change') {
                $hasChange = true;

                break;
            }
        }

        if (! $hasChange) {
            return null;
        }

        $first = $chain[0];

        if (! in_array($first['method'], BlueprintColumnMethods::SINGLE_COLUMN_METHODS, true)) {
            return null;
        }

        $column = $first['args'][0] ?? null;

        if (! is_string($column)) {
            return null;
        }

        return $column;
    }

    private function toFinding(ChangedFile $file, string $table, string $column): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Warning,
            file: $file,
            message: sprintf(
                'Migration redefines column "%s" on table "%s" via ->change(). TicoScope cannot see the '.
                'column\'s previous definition from this migration alone — if the new definition is narrower '.
                '(a shorter string length, a smaller integer size, reduced decimal precision, etc.), this can '.
                'silently truncate existing data. Verify the previous column definition before deploying.',
                $column,
                $table,
            ),
            reasonCode: $column,
        );
    }
}
