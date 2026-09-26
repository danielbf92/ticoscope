<?php

namespace TicoScope\Rules\Queue;

use TicoScope\Classification\FileCategory;
use TicoScope\Classification\FileClassifier;
use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Diff\Diff;
use TicoScope\Findings\Finding;
use TicoScope\Findings\Severity;
use TicoScope\Git\GitDiffReader;
use TicoScope\Php\PublicPropertyExtractor;
use TicoScope\Rules\Rule;

/**
 * Flags a change to a queued Job class's own declared $queue literal.
 * Same reasoning as JobConnectionChangedRule, kept as a separate rule id
 * (not merged into one "routing changed" id) so a team can allow/suppress
 * one but not the other, matching the granularity of every other rule id
 * in this project.
 */
final class JobQueueChangedRule implements Rule
{
    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly FileClassifier $classifier = new FileClassifier(),
        private readonly PublicPropertyExtractor $propertyExtractor = new PublicPropertyExtractor(),
    ) {
    }

    public function id(): string
    {
        return 'queue.job-queue-changed';
    }

    /**
     * @return Finding[]
     */
    public function analyze(Diff $diff): array
    {
        $findings = [];

        foreach ($diff->changedFiles as $file) {
            if ($file->changeType !== ChangeType::Modified && $file->changeType !== ChangeType::Renamed) {
                continue;
            }

            $oldPath = $file->changeType === ChangeType::Renamed ? $file->originalPath : $file->path;
            $newPath = $file->path;

            if ($this->classifier->classifyPath($oldPath) !== FileCategory::QueueJob) {
                continue;
            }

            $oldSource = $this->gitDiffReader->readFile($diff->baseRevision, $oldPath);
            $newSource = $this->gitDiffReader->readFile($diff->headRevision, $newPath);

            if ($oldSource === null || $newSource === null) {
                continue;
            }

            $oldProperties = $this->propertyExtractor->extract($oldSource);
            $newProperties = $this->propertyExtractor->extract($newSource);

            if ($oldProperties === null || $newProperties === null) {
                continue;
            }

            $old = $oldProperties['queue'] ?? null;
            $new = $newProperties['queue'] ?? null;

            if ($old === null || $new === null) {
                continue;
            }

            if (! $old->hasLiteralDefault || ! $new->hasLiteralDefault) {
                continue;
            }

            if ($old->literalDefault === $new->literalDefault) {
                continue;
            }

            $findings[] = $this->toFinding($file, $old->literalDefault, $new->literalDefault);
        }

        return $findings;
    }

    private function toFinding(ChangedFile $file, string|int|float|bool|null $old, string|int|float|bool|null $new): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Warning,
            file: $file,
            message: sprintf(
                "Job's queue name changed from %s to %s. Ensure a worker is listening on the new ".
                'queue before this deploys, or dispatched jobs may go unprocessed.',
                $this->formatLiteral($old),
                $this->formatLiteral($new),
            ),
            reasonCode: 'queue',
        );
    }

    private function formatLiteral(string|int|float|bool|null $value): string
    {
        return match (true) {
            is_string($value) => "'{$value}'",
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => (string) $value,
        };
    }
}
