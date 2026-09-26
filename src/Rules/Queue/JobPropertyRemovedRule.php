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
 * Flags a public property removed from a queued Job class. Already-queued
 * payloads were serialized against the old shape — verified directly (not
 * assumed): unserializing against a class that no longer declares the
 * property does not throw, it emits a PHP 8.2+ dynamic-property deprecation
 * and restores the value anyway. Real risk, just not a crash — see the
 * message text below, which deliberately doesn't overclaim.
 */
final class JobPropertyRemovedRule implements Rule
{
    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly FileClassifier $classifier = new FileClassifier(),
        private readonly PublicPropertyExtractor $propertyExtractor = new PublicPropertyExtractor(),
    ) {
    }

    public function id(): string
    {
        return 'queue.job-property-removed';
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

            foreach ($oldProperties as $name => $property) {
                if (! array_key_exists($name, $newProperties)) {
                    $findings[] = $this->toFinding($file, $name);
                }
            }
        }

        return $findings;
    }

    private function toFinding(ChangedFile $file, string $name): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Warning,
            file: $file,
            message: sprintf(
                'Public property $%s was removed. A job already queued with this property will unserialize it '.
                'as an undeclared dynamic property (deprecated since PHP 8.2), which may behave unexpectedly '.
                'or break on a future PHP version.',
                $name,
            ),
            reasonCode: $name,
        );
    }
}
