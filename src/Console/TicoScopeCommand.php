<?php

namespace TicoScope\Console;

use Illuminate\Console\Command;
use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Diff\Diff;
use TicoScope\Git\GitDiffReader;
use TicoScope\Git\GitException;

final class TicoScopeCommand extends Command
{
    protected $signature = 'ticoscope:check {--base=main : The revision to compare against}';

    protected $description = 'Analyze the changes since a base revision for deployment risk';

    public function handle(GitDiffReader $gitDiffReader): int
    {
        try {
            $diff = $gitDiffReader->compare($this->option('base'));
        } catch (GitException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderDiffSummary($diff);

        return self::SUCCESS;
    }

    private function renderDiffSummary(Diff $diff): void
    {
        $this->components->info(sprintf('Comparing %s → %s', $diff->baseRevision, $diff->headRevision));
        $this->newLine();
        $this->line(sprintf('%d file%s changed', count($diff), count($diff) === 1 ? '' : 's'));

        if (count($diff) === 0) {
            return;
        }

        $this->newLine();

        foreach ($diff->changedFiles as $changedFile) {
            $this->line(sprintf('%-10s%s', $this->labelFor($changedFile->changeType), $this->describe($changedFile)));
        }
    }

    private function labelFor(ChangeType $changeType): string
    {
        return match ($changeType) {
            ChangeType::Added => 'ADDED',
            ChangeType::Modified => 'MODIFIED',
            ChangeType::Deleted => 'DELETED',
            ChangeType::Renamed => 'RENAMED',
        };
    }

    private function describe(ChangedFile $changedFile): string
    {
        if ($changedFile->changeType === ChangeType::Renamed) {
            return sprintf('%s → %s', $changedFile->originalPath, $changedFile->path);
        }

        return $changedFile->path;
    }
}
