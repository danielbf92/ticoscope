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
 * Flags a column added to an EXISTING table (Schema::table(), never
 * Schema::create() — a brand new table has no rows, so this pattern is
 * simply how tables are normally defined) with no ->nullable() and no
 * ->default() in its chain. Only actually harmful if the table already has
 * rows, which this static tool cannot know — hence Warning, not Critical,
 * unlike ColumnDroppedRule/TableDroppedRule's unconditionally destructive
 * framing.
 *
 * The candidate method allowlist below was built by reading the real
 * Illuminate\Database\Schema\Blueprint source, not assumed: methods like
 * timestamps()/softDeletes()/rememberToken()/id() are deliberately excluded
 * because they're already nullable (or an auto-increment primary key)
 * internally, and morphs()-family macros are excluded because they add
 * more than one column per call, which doesn't fit this rule's
 * one-chain-one-column model.
 */
final class NonNullableWithoutDefaultRule implements Rule
{
    private const CANDIDATE_METHODS = [
        'char', 'string', 'tinyText', 'text', 'mediumText', 'longText',
        'integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger',
        'unsignedInteger', 'unsignedTinyInteger', 'unsignedSmallInteger',
        'unsignedMediumInteger', 'unsignedBigInteger',
        'float', 'double', 'decimal', 'unsignedDecimal',
        'boolean', 'enum', 'set', 'json', 'jsonb',
        'date', 'dateTime', 'dateTimeTz', 'time', 'timeTz', 'timestamp', 'timestampTz',
        'year', 'binary', 'uuid', 'ulid', 'ipAddress', 'macAddress',
        'foreignId', 'foreignUuid', 'foreignUlid',
    ];

    private const USE_CURRENT_ELIGIBLE_METHODS = ['timestamp', 'timestampTz'];

    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly FileClassifier $classifier = new FileClassifier(),
        private readonly SchemaCallExtractor $schemaExtractor = new SchemaCallExtractor(),
    ) {
    }

    public function id(): string
    {
        return 'migration.non-nullable-without-default';
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
                    $column = $this->riskyColumn($chain);

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
    private function riskyColumn(array $chain): ?string
    {
        if ($chain === []) {
            return null;
        }

        foreach ($chain as $step) {
            if ($step['method'] === 'change') {
                return null;
            }
        }

        $first = $chain[0];

        if (! in_array($first['method'], self::CANDIDATE_METHODS, true)) {
            return null;
        }

        $column = $first['args'][0] ?? null;

        if (! is_string($column)) {
            return null;
        }

        foreach ($chain as $step) {
            if ($step['method'] === 'nullable' || $step['method'] === 'default') {
                return null;
            }

            if (
                $step['method'] === 'useCurrent'
                && in_array($first['method'], self::USE_CURRENT_ELIGIBLE_METHODS, true)
            ) {
                return null;
            }
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
                'Migration adds column "%s" to table "%s" with no nullable() call and no default(). If "%s" '.
                'already has rows, this may fail outright or behave unexpectedly depending on your database\'s '.
                'strict mode. Add ->nullable() or ->default(...), or confirm the table is empty in every '.
                'environment this runs against.',
                $column,
                $table,
                $table,
            ),
            reasonCode: $column,
        );
    }
}
