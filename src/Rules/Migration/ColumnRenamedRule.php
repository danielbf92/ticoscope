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
use TicoScope\Php\SchemaCallExtractor;
use TicoScope\Rules\Rule;

/**
 * Flags a migration renaming a column. Unlike ColumnDroppedRule/
 * TableDroppedRule, this isn't destructive to data — the risk is that
 * application code and in-flight queries still referencing the old column
 * name break the moment this migration runs, the same class of risk the
 * Queue identity-change rules flag, hence Warning rather than Critical.
 */
final class ColumnRenamedRule implements Rule
{
    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly FileClassifier $classifier = new FileClassifier(),
        private readonly SchemaCallExtractor $schemaExtractor = new SchemaCallExtractor(),
    ) {
    }

    public function id(): string
    {
        return 'migration.column-renamed';
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
                foreach ($operation->statements as $chain) {
                    foreach ($chain as $call) {
                        if ($call['method'] !== 'renameColumn') {
                            continue;
                        }

                        $old = $call['args'][0] ?? null;
                        $new = $call['args'][1] ?? null;

                        if (! is_string($old) || ! is_string($new)) {
                            continue;
                        }

                        $findings[] = $this->toFinding($file, $operation->table, $old, $new);
                    }
                }
            }
        }

        return $findings;
    }

    private function toFinding(ChangedFile $file, string $table, string $old, string $new): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Warning,
            file: $file,
            message: sprintf(
                'Migration renames column "%s" to "%s" on table "%s". Application code and queries still '.
                'referencing "%s" will break as soon as this migration runs.',
                $old,
                $new,
                $table,
                $old,
            ),
            reasonCode: $old,
        );
    }
}
