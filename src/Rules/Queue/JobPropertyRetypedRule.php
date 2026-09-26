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
 * Flags a public property on a queued Job class whose declared type
 * changed (including untyped <-> typed transitions). Verified directly:
 * unserializing an old-typed value into a newly incompatible-typed
 * property throws a real TypeError, not a hypothetical one.
 */
final class JobPropertyRetypedRule implements Rule
{
    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly FileClassifier $classifier = new FileClassifier(),
        private readonly PublicPropertyExtractor $propertyExtractor = new PublicPropertyExtractor(),
    ) {
    }

    public function id(): string
    {
        return 'queue.job-property-retyped';
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

            foreach ($oldProperties as $name => $oldProperty) {
                if (! array_key_exists($name, $newProperties)) {
                    continue;
                }

                $newProperty = $newProperties[$name];

                if ($oldProperty->type !== $newProperty->type) {
                    $findings[] = $this->toFinding($file, $name, $oldProperty->type, $newProperty->type);
                }
            }
        }

        return $findings;
    }

    private function toFinding(ChangedFile $file, string $name, ?string $oldType, ?string $newType): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Warning,
            file: $file,
            message: sprintf(
                'Public property $%s changed type from %s to %s. Jobs already queued with the previous type '.
                'serialized may no longer deserialize correctly after deployment.',
                $name,
                $oldType ?? '(untyped)',
                $newType ?? '(untyped)',
            ),
            reasonCode: $name,
        );
    }
}
