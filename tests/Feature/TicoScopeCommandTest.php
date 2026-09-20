<?php

use TicoScope\Git\GitDiffReader;
use TicoScope\Tests\Support\TemporaryGitRepository;

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
        ->assertExitCode(0);
});

it('reports no changed files when base and head are identical', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n");
    $repo->commit('base');

    $this->app->instance(GitDiffReader::class, new GitDiffReader($repo->path()));

    $this->artisan('ticoscope:check', ['--base' => 'main'])
        ->expectsOutputToContain('0 files changed')
        ->expectsOutputToContain('No findings.')
        ->assertExitCode(0);
});

it('prints a finding for a newly introduced env() call with no fallback', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n];\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n    'endpoint' => env('REPORTING_ENDPOINT'),\n];\n");
    $repo->commit('add risky env call');

    $this->app->instance(GitDiffReader::class, new GitDiffReader($repo->path()));

    $this->artisan('ticoscope:check', ['--base' => 'main'])
        ->expectsOutputToContain('Findings (1)')
        ->expectsOutputToContain('[config.env-without-default] config/services.php')
        ->expectsOutputToContain('REPORTING_ENDPOINT')
        ->assertExitCode(0);
});

it('fails clearly when the working directory is not a Git repository', function () {
    $directory = sys_get_temp_dir().'/ticoscope-not-a-repo-'.uniqid();
    mkdir($directory);

    $this->app->instance(GitDiffReader::class, new GitDiffReader($directory));

    $this->artisan('ticoscope:check', ['--base' => 'main'])
        ->expectsOutputToContain('is not a Git repository')
        ->assertExitCode(1);

    rmdir($directory);
});
