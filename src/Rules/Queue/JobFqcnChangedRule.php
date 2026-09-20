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
use TicoScope\Php\FqcnExtractor;
use TicoScope\Rules\Rule;

/**
 * Flags a change to a queued Job class's fully-qualified class name (FQCN) —
 * not a Git file rename, which is a different and often harmless thing.
 *
 * The candidate gate is deliberately based on the file's *pre-change* path
 * (originalPath for a rename, path otherwise), answering "was this a Job
 * candidate before this deployment," not "is it one after." A Job that moves
 * out of app/Jobs while also changing identity still risks already-queued
 * payloads referencing the old FQCN; a non-Job file that happens to move
 * into app/Jobs never had any such payloads to begin with.
 */
final class JobFqcnChangedRule implements Rule
{
    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly FileClassifier $classifier = new FileClassifier(),
        private readonly FqcnExtractor $fqcnExtractor = new FqcnExtractor(),
    ) {
    }

    public function id(): string
    {
        return 'queue.job-fqcn-changed';
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

            $oldFqcn = $this->fqcnExtractor->extract($oldSource);
            $newFqcn = $this->fqcnExtractor->extract($newSource);

            if ($oldFqcn === null || $newFqcn === null || $oldFqcn === $newFqcn) {
                continue;
            }

            $findings[] = $this->toFinding($file, $oldFqcn, $newFqcn);
        }

        return $findings;
    }

    private function toFinding(ChangedFile $file, string $oldFqcn, string $newFqcn): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Warning,
            file: $file,
            message: sprintf(
                'Job class identity changed from %s to %s. Jobs queued under the previous class name '.
                'may no longer deserialize or resolve correctly after deployment.',
                $oldFqcn,
                $newFqcn,
            ),
            reasonCode: $oldFqcn,
        );
    }
}
