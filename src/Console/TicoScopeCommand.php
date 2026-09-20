<?php

namespace TicoScope\Console;

use Illuminate\Console\Command;
use TicoScope\Analysis\Analyzer;
use TicoScope\Classification\FileClassifier;
use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Diff\Diff;
use TicoScope\Findings\Finding;
use TicoScope\Git\GitDiffReader;
use TicoScope\Git\GitException;

final class TicoScopeCommand extends Command
{
    protected $signature = 'ticoscope:check {--base=main : The revision to compare against}';

    protected $description = 'Analyze the changes since a base revision for deployment risk';

    public function handle(GitDiffReader $gitDiffReader, Analyzer $analyzer): int
    {
        try {
            $diff = $gitDiffReader->compare($this->option('base'));
        } catch (GitException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderDiffSummary($diff);
        $this->newLine();
        $this->renderFindings($analyzer->analyze($diff));

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

        $classifier = new FileClassifier();

        foreach ($diff->changedFiles as $changedFile) {
            $this->line(sprintf(
                '%-10s%s [%s]',
                $this->labelFor($changedFile->changeType),
                $this->describe($changedFile),
                $classifier->classify($changedFile)->value,
            ));
        }
    }

    /**
     * @param Finding[] $findings
     */
    private function renderFindings(array $findings): void
    {
        if ($findings === []) {
            $this->line('No findings.');

            return;
        }

        $this->line(sprintf('Findings (%d)', count($findings)));

        foreach ($findings as $finding) {
            $this->line(sprintf(
                '  %-8s [%s] %s',
                strtoupper($finding->severity->value),
                $finding->ruleId,
                $finding->file->path,
            ));
            $this->line(sprintf('    %s', $finding->message));
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
