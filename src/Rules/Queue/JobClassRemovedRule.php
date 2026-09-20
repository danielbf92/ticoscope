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
 * Flags a queued Job class that was removed outright. Handled separately
 * from JobFqcnChangedRule (rather than folded into it) because a deletion
 * has no "new" side to compare against — it needs only the subset of that
 * rule's machinery that reads a file's content at the base revision.
 */
final class JobClassRemovedRule implements Rule
{
    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly FileClassifier $classifier = new FileClassifier(),
        private readonly FqcnExtractor $fqcnExtractor = new FqcnExtractor(),
    ) {
    }

    public function id(): string
    {
        return 'queue.job-class-removed';
    }

    /**
     * @return Finding[]
     */
    public function analyze(Diff $diff): array
    {
        $findings = [];

        foreach ($diff->changedFiles as $file) {
            if ($file->changeType !== ChangeType::Deleted) {
                continue;
            }

            if ($this->classifier->classifyPath($file->path) !== FileCategory::QueueJob) {
                continue;
            }

            $oldSource = $this->gitDiffReader->readFile($diff->baseRevision, $file->path);

            if ($oldSource === null) {
                continue;
            }

            $oldFqcn = $this->fqcnExtractor->extract($oldSource);

            if ($oldFqcn === null) {
                continue;
            }

            $findings[] = $this->toFinding($file, $oldFqcn);
        }

        return $findings;
    }

    private function toFinding(ChangedFile $file, string $oldFqcn): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Warning,
            file: $file,
            message: sprintf(
                'Job class %s was removed. Jobs queued under this class may no longer deserialize '.
                'or resolve correctly after deployment.',
                $oldFqcn,
            ),
            reasonCode: $oldFqcn,
        );
    }
}
