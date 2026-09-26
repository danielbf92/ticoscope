<?php

namespace TicoScope\Rules\Composer;

use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Diff\Diff;
use TicoScope\Findings\Finding;
use TicoScope\Findings\Severity;
use TicoScope\Git\GitDiffReader;
use TicoScope\Php\ComposerLockParser;
use TicoScope\Rules\Rule;

/**
 * Flags a dependency present in the old composer.lock and absent from the
 * new one. Warning severity — one level above
 * ComposerPackageMajorBumpRule's Info: a version change might break
 * something, a fully removed dependency is a certainty for any code still
 * referencing it (a class-not-found failure at runtime).
 *
 * Only composer.lock's *resolved* versions are read, never composer.json's
 * constraints. Gated on a direct path check, not FileClassifier, since
 * FileCategory::Composer also matches composer.json, which this rule has
 * no use for.
 */
final class ComposerPackageRemovedRule implements Rule
{
    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly ComposerLockParser $parser = new ComposerLockParser(),
    ) {
    }

    public function id(): string
    {
        return 'composer.package-removed';
    }

    /**
     * @return Finding[]
     */
    public function analyze(Diff $diff): array
    {
        $findings = [];

        foreach ($diff->changedFiles as $file) {
            if ($file->changeType !== ChangeType::Modified) {
                continue;
            }

            if ($file->path !== 'composer.lock') {
                continue;
            }

            $oldSource = $this->gitDiffReader->readFile($diff->baseRevision, 'composer.lock');
            $newSource = $this->gitDiffReader->readFile($diff->headRevision, 'composer.lock');

            if ($oldSource === null || $newSource === null) {
                continue;
            }

            $oldVersions = $this->parser->parse($oldSource);
            $newVersions = $this->parser->parse($newSource);

            if ($oldVersions === null || $newVersions === null) {
                continue;
            }

            foreach ($oldVersions as $package => $oldVersion) {
                if (array_key_exists($package, $newVersions)) {
                    continue;
                }

                $findings[] = $this->toFinding($file, $package, $oldVersion);
            }
        }

        return $findings;
    }

    private function toFinding(ChangedFile $file, string $package, string $oldVersion): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Warning,
            file: $file,
            message: sprintf(
                'Dependency "%s" (was %s) has been removed from composer.lock. Code or configuration that '.
                'still depends on this package may fail after deployment — confirm it is no longer required.',
                $package,
                $oldVersion,
            ),
            reasonCode: $package,
        );
    }
}
