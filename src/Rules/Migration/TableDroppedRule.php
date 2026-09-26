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
 * Flags a migration dropping a table outright via Schema::drop()/
 * dropIfExists() — the direct, equally-severe sibling of ColumnDroppedRule
 * VISION names alongside it ("dropping a column or table").
 */
final class TableDroppedRule implements Rule
{
    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly FileClassifier $classifier = new FileClassifier(),
        private readonly SchemaCallExtractor $schemaExtractor = new SchemaCallExtractor(),
    ) {
    }

    public function id(): string
    {
        return 'migration.table-dropped';
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

            foreach ($analysis->droppedTables as $table) {
                $findings[] = $this->toFinding($file, $table);
            }
        }

        return $findings;
    }

    private function toFinding(ChangedFile $file, string $table): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Critical,
            file: $file,
            message: sprintf(
                'Migration drops table "%s". This is a destructive, typically irreversible operation. '.
                'Confirm a backup/rollback plan exists.',
                $table,
            ),
            reasonCode: $table,
        );
    }
}
