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
 * Flags a migration dropping a column — a destructive, typically
 * irreversible operation. Unlike every Queue rule, this needs no pre/post-
 * change comparison: a migration either declares a risky operation in its
 * current (head-revision) content, or it doesn't, regardless of whether
 * Git reports it as Added, Modified, or Renamed.
 */
final class ColumnDroppedRule implements Rule
{
    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly FileClassifier $classifier = new FileClassifier(),
        private readonly SchemaCallExtractor $schemaExtractor = new SchemaCallExtractor(),
    ) {
    }

    public function id(): string
    {
        return 'migration.column-dropped';
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
                        if ($call['method'] !== 'dropColumn') {
                            continue;
                        }

                        foreach ($this->columnNames($call['args']) as $column) {
                            $findings[] = $this->toFinding($file, $operation->table, $column);
                        }
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<string|null|list<string>> $args
     * @return string[]
     */
    private function columnNames(array $args): array
    {
        if (! array_key_exists(0, $args)) {
            return [];
        }

        $first = $args[0];

        if (is_string($first)) {
            return [$first];
        }

        if (is_array($first)) {
            return $first;
        }

        return [];
    }

    private function toFinding(ChangedFile $file, string $table, string $column): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Critical,
            file: $file,
            message: sprintf(
                'Migration drops column "%s" on table "%s". This is a destructive, typically irreversible '.
                'operation. Confirm a backup/rollback plan exists.',
                $column,
                $table,
            ),
            reasonCode: $column,
        );
    }
}
