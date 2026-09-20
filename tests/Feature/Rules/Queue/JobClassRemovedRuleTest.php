<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Queue\JobClassRemovedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzeJobRemoval(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new JobClassRemovedRule($gitDiffReader))->analyze($diff);
}

it('fires when a queued Job class is deleted', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('app/Jobs/GenerateReport.php');
    $repo->commit('remove job');

    $findings = analyzeJobRemoval($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('queue.job-class-removed');
    expect($findings[0]->reasonCode)->toBe('App\Jobs\GenerateReport');
    expect($findings[0]->message)->toContain('App\Jobs\GenerateReport');
    expect($findings[0]->message)->toContain('may no longer deserialize or resolve correctly');
});

it('does not fire when a deleted file was not a Job candidate', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Services/GenerateReport.php', "<?php\n\nnamespace App\\Services;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('app/Services/GenerateReport.php');
    $repo->commit('remove a non-job file');

    expect(analyzeJobRemoval($repo))->toBe([]);
});

it('does not fire for a modified or renamed Job (not deleted)', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->renameFile('app/Jobs/GenerateReport.php', 'app/Jobs/Reports/GenerateReport.php');
    $repo->commit('move, not delete');

    expect(analyzeJobRemoval($repo))->toBe([]);
});

it('does not fire and does not throw when the removed Job had unparseable content', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs\n\nclass Gene");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('app/Jobs/GenerateReport.php');
    $repo->commit('remove malformed job');

    $findings = null;
    $exception = null;

    try {
        $findings = analyzeJobRemoval($repo);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($findings)->toBe([]);
});

it('does not swallow an unexpected Git/infrastructure failure from readFile()', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('app/Jobs/GenerateReport.php');
    $repo->commit('remove job');

    // The Diff itself is computed successfully from the real repo, but the
    // reader handed to the rule points at a working directory that no
    // longer exists — readFile() must throw (proven separately in
    // GitDiffReaderTest), and this asserts the rule lets that exception
    // propagate rather than treating it as "no finding".
    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new JobClassRemovedRule($vanishedReader))->analyze($diff);
})->throws(GitCommandFailedException::class);
