<?php

namespace TicoScope\Console;

use Illuminate\Console\Command;
use TicoScope\Analysis\Analyzer;
use TicoScope\Classification\FileClassifier;
use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Diff\Diff;
use TicoScope\Findings\Finding;
use TicoScope\Findings\Severity;
use TicoScope\Git\GitDiffReader;
use TicoScope\Git\GitException;
use TicoScope\Reporting\ConsoleReporter;
use TicoScope\Reporting\JsonReporter;

final class TicoScopeCommand extends Command
{
    protected $signature = 'ticoscope:check
        {--base=main : The revision to compare against}
        {--fail-on= : Minimum severity (info, warning, or critical — lowercase) that causes a non-zero exit code}
        {--format=console : Output format (console or json)}';

    protected $description = 'Analyze the changes since a base revision for deployment risk';

    public function handle(GitDiffReader $gitDiffReader, Analyzer $analyzer): int
    {
        $formatOption = $this->option('format');

        if ($formatOption !== 'console' && $formatOption !== 'json') {
            $this->components->error(sprintf(
                'Invalid --format value "%s". Expected one of: console, json.',
                $formatOption,
            ));

            return self::INVALID;
        }

        $isJson = $formatOption === 'json';

        $threshold = null;
        $failOnOption = $this->option('fail-on');

        if ($failOnOption !== null) {
            $threshold = Severity::tryFrom($failOnOption);

            if ($threshold === null) {
                $message = sprintf(
                    'Invalid --fail-on value "%s". Expected one of: info, warning, critical.',
                    $failOnOption,
                );

                if ($isJson) {
                    $this->line($this->errorDocument($message));
                } else {
                    $this->components->error($message);
                }

                return self::INVALID;
            }
        }

        try {
            $diff = $gitDiffReader->compare($this->option('base'));
        } catch (GitException $exception) {
            if ($isJson) {
                $this->line($this->errorDocument($exception->getMessage()));
            } else {
                $this->components->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        $findings = $analyzer->analyze($diff);

        if ($isJson) {
            $this->line((new JsonReporter())->report($findings));
        } else {
            $this->renderDiffSummary($diff);
            $this->newLine();

            foreach (explode("\n", (new ConsoleReporter())->report($findings)) as $line) {
                $this->line($line);
            }
        }

        if ($threshold !== null && $this->anyFindingMeets($findings, $threshold)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function errorDocument(string $message): string
    {
        $json = json_encode([
            'schema_version' => '1',
            'error' => $message,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '{"schema_version":"1","error":"unknown error"}' : $json;
    }

    /**
     * @param Finding[] $findings
     */
    private function anyFindingMeets(array $findings, Severity $threshold): bool
    {
        foreach ($findings as $finding) {
            if ($finding->severity->meets($threshold)) {
                return true;
            }
        }

        return false;
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
