<?php

namespace TicoScope\Git;

use Symfony\Component\Process\Process;
use Throwable;
use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Diff\Diff;

/**
 * Turns a comparison between two Git revisions into a domain Diff.
 *
 * Comparisons are merge-base relative (Git's "three-dot" semantics: the
 * revision range is passed to `git diff` as a single "base...head" argument),
 * not a literal two-tree diff. This matches the tool's premise of "what does
 * this release introduce": if the base revision has moved forward since the
 * head revision branched from it, those unrelated changes must not appear in
 * the Diff.
 */
final class GitDiffReader
{
    public function __construct(
        private readonly string $workingDirectory,
    ) {
    }

    public function compare(string $baseRevision, string $headRevision = 'HEAD'): Diff
    {
        $this->assertIsGitRepository();
        $this->assertRevisionExists($baseRevision);
        $this->assertRevisionExists($headRevision);

        $changedFiles = $this->buildChangedFiles($baseRevision, $headRevision);

        return new Diff($baseRevision, $headRevision, $changedFiles);
    }

    private function assertIsGitRepository(): void
    {
        $process = $this->run(['rev-parse', '--is-inside-work-tree']);

        if (! $process->isSuccessful() || trim($process->getOutput()) !== 'true') {
            throw NotAGitRepositoryException::forPath($this->workingDirectory);
        }
    }

    private function assertRevisionExists(string $revision): void
    {
        $process = $this->run(['rev-parse', '--verify', '--quiet', $revision]);

        if (! $process->isSuccessful()) {
            throw UnknownRevisionException::forRevision($revision);
        }
    }

    /**
     * @return ChangedFile[]
     */
    private function buildChangedFiles(string $baseRevision, string $headRevision): array
    {
        $process = $this->run(['diff', '--raw', '-p', '-z', '-M', "{$baseRevision}...{$headRevision}"]);

        if (! $process->isSuccessful()) {
            throw GitCommandFailedException::fromProcessFailure($process->getCommandLine(), $process->getErrorOutput());
        }

        return $this->parseDiffOutput($process->getOutput());
    }

    /**
     * Parses the combined output of `git diff --raw -p -z -M`: a NUL-delimited
     * raw section (one or more ":srcMode dstMode srcSha dstSha STATUS\0path\0"
     * records, two path tokens for renames), followed by an empty token that
     * marks the boundary, followed by the ordinary newline-formatted patch
     * text for every record, in the same order as the raw section.
     *
     * @return ChangedFile[]
     */
    private function parseDiffOutput(string $output): array
    {
        if ($output === '') {
            return [];
        }

        $tokens = explode("\0", $output);
        $cursor = 0;
        $entries = [];

        while ($cursor < count($tokens) && $tokens[$cursor] !== '') {
            $rawRecord = $tokens[$cursor];

            if (! str_starts_with($rawRecord, ':')) {
                throw GitCommandFailedException::malformedOutput(
                    sprintf('expected a raw diff record starting with ":", got "%s".', $rawRecord),
                );
            }

            $fields = preg_split('/\s+/', ltrim($rawRecord, ':'));

            if ($fields === false || count($fields) < 5) {
                throw GitCommandFailedException::malformedOutput(
                    sprintf('could not parse raw diff record "%s".', $rawRecord),
                );
            }

            $status = $fields[4];
            $cursor++;

            if (! array_key_exists($cursor, $tokens)) {
                throw GitCommandFailedException::malformedOutput('raw diff record is missing its path.');
            }

            if (str_starts_with($status, 'R')) {
                $originalPath = $tokens[$cursor];
                $cursor++;

                if (! array_key_exists($cursor, $tokens)) {
                    throw GitCommandFailedException::malformedOutput('rename record is missing its destination path.');
                }

                $path = $tokens[$cursor];
                $cursor++;
            } else {
                $originalPath = null;
                $path = $tokens[$cursor];
                $cursor++;
            }

            $entries[] = [
                'status' => $status,
                'path' => $path,
                'originalPath' => $originalPath,
            ];
        }

        $patchBlob = array_key_exists($cursor, $tokens)
            ? implode("\0", array_slice($tokens, $cursor + 1))
            : '';

        $patches = $this->splitPatchIntoBlocks($patchBlob);

        if (count($patches) !== count($entries)) {
            throw GitCommandFailedException::malformedOutput(sprintf(
                'expected %d patch block(s) but found %d.',
                count($entries),
                count($patches),
            ));
        }

        $changedFiles = [];

        foreach ($entries as $index => $entry) {
            $changedFiles[] = new ChangedFile(
                path: $entry['path'],
                changeType: $this->mapStatusToChangeType($entry['status']),
                patch: $patches[$index] !== '' ? $patches[$index] : null,
                originalPath: $entry['originalPath'],
            );
        }

        return $changedFiles;
    }

    /**
     * @return string[]
     */
    private function splitPatchIntoBlocks(string $patchBlob): array
    {
        if (trim($patchBlob) === '') {
            return [];
        }

        // A hunk line is always sigil-prefixed ('+', '-', ' '), so a line
        // starting at column 0 with "diff --git " can only be a real header.
        $blocks = preg_split('/^(?=diff --git )/m', $patchBlob);

        $blocks = array_values(array_filter($blocks, fn (string $block): bool => $block !== ''));

        return array_map(fn (string $block): string => rtrim($block, "\n"), $blocks);
    }

    private function mapStatusToChangeType(string $status): ChangeType
    {
        return match (true) {
            str_starts_with($status, 'A') => ChangeType::Added,
            str_starts_with($status, 'D') => ChangeType::Deleted,
            str_starts_with($status, 'R') => ChangeType::Renamed,
            default => ChangeType::Modified,
        };
    }

    private function run(array $arguments): Process
    {
        $process = new Process(['git', ...$arguments], $this->workingDirectory);

        try {
            $process->run();
        } catch (Throwable $exception) {
            throw GitCommandFailedException::fromProcessFailure($process->getCommandLine(), $exception->getMessage());
        }

        return $process;
    }
}
