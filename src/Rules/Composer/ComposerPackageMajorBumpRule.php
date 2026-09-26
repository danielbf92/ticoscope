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
 * Flags a dependency whose resolved major version changed in composer.lock
 * — direction-agnostic (a downgrade is just as much a breaking-change
 * signal as a bump). Info severity: unlike every Warning/Critical rule
 * this project ships, a major version change *might* introduce a breaking
 * change worth a look, but frequently doesn't for a given consumer's
 * actual usage.
 *
 * Only composer.lock's *resolved* versions are read, never composer.json's
 * constraints — a constraint change doesn't tell us whether a bump
 * actually happened. Gated on a direct path check, not FileClassifier,
 * since FileCategory::Composer also matches composer.json, which this
 * rule has no use for.
 */
final class ComposerPackageMajorBumpRule implements Rule
{
    public function __construct(
        private readonly GitDiffReader $gitDiffReader,
        private readonly ComposerLockParser $parser = new ComposerLockParser(),
    ) {
    }

    public function id(): string
    {
        return 'composer.package-major-bump';
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
                if (! array_key_exists($package, $newVersions)) {
                    continue;
                }

                $newVersion = $newVersions[$package];

                if ($oldVersion === $newVersion) {
                    continue;
                }

                $oldMajor = $this->majorVersion($oldVersion);
                $newMajor = $this->majorVersion($newVersion);

                if ($oldMajor === null || $newMajor === null || $oldMajor === $newMajor) {
                    continue;
                }

                $findings[] = $this->toFinding($file, $package, $oldVersion, $newVersion);
            }
        }

        return $findings;
    }

    private function majorVersion(string $version): ?string
    {
        if (preg_match('/^v?(\d+)\./', $version, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function toFinding(ChangedFile $file, string $package, string $oldVersion, string $newVersion): Finding
    {
        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Info,
            file: $file,
            message: sprintf(
                'Dependency "%s" major version changed from %s to %s. Review the package\'s changelog for '.
                'breaking changes before deploying.',
                $package,
                $oldVersion,
                $newVersion,
            ),
            reasonCode: $package,
        );
    }
}
