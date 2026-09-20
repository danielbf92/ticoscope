<?php

use TicoScope\Git\GitDiffReader;
use TicoScope\Tests\Support\TemporaryGitRepository;

it('reports the real changed files between the base and head revisions', function () {
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
        ->expectsOutputToContain('ADDED     app/Jobs/ExampleJob.php')
        ->expectsOutputToContain('ADDED     config/services.php')
        ->expectsOutputToContain('RENAMED   app/ToRename.php → app/Renamed.php')
        ->assertExitCode(0);
});

it('reports no changed files when base and head are identical', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n");
    $repo->commit('base');

    $this->app->instance(GitDiffReader::class, new GitDiffReader($repo->path()));

    $this->artisan('ticoscope:check', ['--base' => 'main'])
        ->expectsOutputToContain('0 files changed')
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
