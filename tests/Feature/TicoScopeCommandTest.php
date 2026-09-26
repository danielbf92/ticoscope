<?php

use Illuminate\Console\Command;
use TicoScope\Analysis\Analyzer;
use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Diff\Diff;
use TicoScope\Findings\Finding;
use TicoScope\Findings\Severity;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Rule;
use TicoScope\Tests\Support\TemporaryGitRepository;

/**
 * Builds a minimal, always-comparable repo and an Analyzer that returns
 * exactly the given findings regardless of the real diff content. This
 * tests the command's own --fail-on/exit-code wiring in isolation from any
 * specific Rule's correctness (already covered by each Rule's own test
 * suite) — the command must gate on severity the same way no matter which
 * Rule produced a Finding. Container binding stays in the calling it()
 * closure, since $this->app is protected and only accessible from there.
 *
 * Returns the TemporaryGitRepository too, even though callers don't use it
 * directly: it must be assigned to a variable in the calling scope so it
 * stays alive for the rest of the test. TemporaryGitRepository deletes its
 * directory in __destruct(); without a live reference, it would go out of
 * scope and get garbage-collected the moment this function returns —
 * deleting the working directory before the command ever runs against it,
 * which surfaces as a GitCommandFailedException (an unrelated
 * infrastructure failure), not the exit code the test actually means to
 * assert on.
 *
 * @param Finding[] $findings
 * @return array{0: GitDiffReader, 1: Analyzer, 2: TemporaryGitRepository}
 */
function fakeFindingsSetup(array $findings): array
{
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n");
    $repo->commit('base');

    $fakeRule = new class($findings) implements Rule
    {
        public function __construct(private readonly array $findings)
        {
        }

        public function id(): string
        {
            return 'test.fake';
        }

        public function analyze(Diff $diff): array
        {
            return $this->findings;
        }
    };

    return [new GitDiffReader($repo->path()), new Analyzer([$fakeRule]), $repo];
}

function fakeFinding(Severity $severity): Finding
{
    return new Finding(
        ruleId: 'test.fake',
        severity: $severity,
        file: new ChangedFile(path: 'app/Existing.php', changeType: ChangeType::Modified),
        message: 'a fake finding for exit-code testing',
    );
}

it('reports the real changed files, their classification, and no findings', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->writeFile('app/ToRename.php', "<?php\n// to rename\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/ExampleJob.php', "<?php\n// example job\n");
    $repo->writeFile('config/services.php', "<?php\nreturn [];\n");
    $repo->commit('add job and config');
    $repo->renameFile('app/ToRename.php', 'app/Renamed.php');
    $repo->commit('rename');

    $this->app->instance(GitDiffReader::class, new GitDiffReader($repo->path()));

    $this->artisan('ticoscope:check', ['--base' => 'main'])
        ->expectsOutputToContain('Comparing main → HEAD')
        ->expectsOutputToContain('3 files changed')
        ->expectsOutputToContain('ADDED     app/Jobs/ExampleJob.php [queue-job]')
        ->expectsOutputToContain('ADDED     config/services.php [config]')
        ->expectsOutputToContain('RENAMED   app/ToRename.php → app/Renamed.php [unclassified]')
        ->expectsOutputToContain('No findings.')
        ->assertExitCode(Command::SUCCESS);
});

it('reports no changed files when base and head are identical', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n");
    $repo->commit('base');

    $this->app->instance(GitDiffReader::class, new GitDiffReader($repo->path()));

    $this->artisan('ticoscope:check', ['--base' => 'main'])
        ->expectsOutputToContain('0 files changed')
        ->expectsOutputToContain('No findings.')
        ->assertExitCode(Command::SUCCESS);
});

it('prints a finding for a newly introduced env() call with no fallback', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n];\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n    'endpoint' => env('REPORTING_ENDPOINT'),\n];\n");
    $repo->commit('add risky env call');

    $this->app->instance(GitDiffReader::class, new GitDiffReader($repo->path()));

    // Each expectsOutputToContain() assertion must target a distinct printed
    // line: Laravel's testing helper matches each expectation against a
    // single doWrite() call, so two expectations aimed at substrings on the
    // *same* line only ever satisfy the first — hence one combined
    // assertion for the finding's rule-id-and-message line below, rather
    // than two separate ones.
    $this->artisan('ticoscope:check', ['--base' => 'main'])
        ->expectsOutputToContain('WARNING (1)')
        ->expectsOutputToContain('! config/services.php')
        ->expectsOutputToContain('[config.env-without-default] New env() call for REPORTING_ENDPOINT has no fallback.')
        ->expectsOutputToContain('1 finding (1 warning).')
        ->assertExitCode(Command::SUCCESS);
});

it('fails clearly when the working directory is not a Git repository', function () {
    $directory = sys_get_temp_dir().'/ticoscope-not-a-repo-'.uniqid();
    mkdir($directory);

    $this->app->instance(GitDiffReader::class, new GitDiffReader($directory));

    $this->artisan('ticoscope:check', ['--base' => 'main'])
        ->expectsOutputToContain('is not a Git repository')
        ->assertExitCode(Command::FAILURE);

    rmdir($directory);
});

// --fail-on matrix — a fake Analyzer isolates this from any specific Rule.

it('--fail-on=info fails on an Info finding', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([fakeFinding(Severity::Info)]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main', '--fail-on' => 'info'])
        ->assertExitCode(Command::FAILURE);
});

it('--fail-on=info fails on a Warning finding', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([fakeFinding(Severity::Warning)]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main', '--fail-on' => 'info'])
        ->assertExitCode(Command::FAILURE);
});

it('--fail-on=info fails on a Critical finding', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([fakeFinding(Severity::Critical)]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main', '--fail-on' => 'info'])
        ->assertExitCode(Command::FAILURE);
});

it('--fail-on=warning fails on a Warning finding', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([fakeFinding(Severity::Warning)]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main', '--fail-on' => 'warning'])
        ->assertExitCode(Command::FAILURE);
});

it('--fail-on=warning fails on a Critical finding', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([fakeFinding(Severity::Critical)]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main', '--fail-on' => 'warning'])
        ->assertExitCode(Command::FAILURE);
});

it('--fail-on=warning succeeds when only Info findings exist', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([fakeFinding(Severity::Info)]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main', '--fail-on' => 'warning'])
        ->assertExitCode(Command::SUCCESS);
});

it('--fail-on=critical fails on a Critical finding', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([fakeFinding(Severity::Critical)]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main', '--fail-on' => 'critical'])
        ->assertExitCode(Command::FAILURE);
});

it('--fail-on=critical succeeds when only Warning findings exist', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([fakeFinding(Severity::Warning)]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main', '--fail-on' => 'critical'])
        ->assertExitCode(Command::SUCCESS);
});

it('--fail-on=critical succeeds when only Info findings exist', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([fakeFinding(Severity::Info)]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main', '--fail-on' => 'critical'])
        ->assertExitCode(Command::SUCCESS);
});

it('omitting --fail-on succeeds regardless of findings', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([fakeFinding(Severity::Critical)]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main'])
        ->assertExitCode(Command::SUCCESS);
});

it('--fail-on=warning with zero findings succeeds', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main', '--fail-on' => 'warning'])
        ->assertExitCode(Command::SUCCESS);
});

it('--fail-on is deliberately lowercase-only: an uppercase value is invalid', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main', '--fail-on' => 'WARNING'])
        ->expectsOutputToContain('Invalid --fail-on value')
        ->assertExitCode(Command::INVALID);
});

it('an unrecognized --fail-on value is invalid', function () {
    [$gitDiffReader, $analyzer, $keepRepoAlive] = fakeFindingsSetup([]);
    $this->app->instance(GitDiffReader::class, $gitDiffReader);
    $this->app->instance(Analyzer::class, $analyzer);

    $this->artisan('ticoscope:check', ['--base' => 'main', '--fail-on' => 'bogus'])
        ->expectsOutputToContain('Invalid --fail-on value')
        ->assertExitCode(Command::INVALID);
});
