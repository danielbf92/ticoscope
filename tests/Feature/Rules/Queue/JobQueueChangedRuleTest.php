<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Queue\JobQueueChangedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzeQueueChange(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new JobQueueChangedRule($gitDiffReader))->analyze($diff);
}

it('fires when the declared queue literal changes', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$queue = 'default';\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$queue = 'reports';\n}\n");
    $repo->commit('change queue');

    $findings = analyzeQueueChange($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('queue.job-queue-changed');
    expect($findings[0]->reasonCode)->toBe('queue');
    expect($findings[0]->message)->toContain("from 'default' to 'reports'");
});

it('does not fire when the queue is unchanged', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$queue = 'default';\n\n    public function handle(): void\n    {\n    }\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$queue = 'default';\n\n    public function handle(): void\n    {\n        logger('running');\n    }\n}\n");
    $repo->commit('touch method body only');

    expect(analyzeQueueChange($repo))->toBe([]);
});

it('does not fire when the old side has a non-literal default', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$queue = config('queue.name');\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$queue = 'reports';\n}\n");
    $repo->commit('change queue');

    expect(analyzeQueueChange($repo))->toBe([]);
});

it('does not fire when the new side has a non-literal default', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$queue = 'default';\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$queue = config('queue.name');\n}\n");
    $repo->commit('change queue to a non-literal');

    expect(analyzeQueueChange($repo))->toBe([]);
});

it('does not fire when the queue is not declared on either side', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$orderId;\n}\n");
    $repo->commit('unrelated retype');

    expect(analyzeQueueChange($repo))->toBe([]);
});

it('does not fire for a non-QueueJob candidate file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Services/GenerateReport.php', "<?php\n\nnamespace App\\Services;\n\nclass GenerateReport\n{\n    public string \$queue = 'default';\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/GenerateReport.php', "<?php\n\nnamespace App\\Services;\n\nclass GenerateReport\n{\n    public string \$queue = 'reports';\n}\n");
    $repo->commit('change queue on a non-job file');

    expect(analyzeQueueChange($repo))->toBe([]);
});

it('does not fire for a newly added Job class', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$queue = 'default';\n}\n");
    $repo->commit('add a new job');

    expect(analyzeQueueChange($repo))->toBe([]);
});

it('does not fire and does not throw when content is unreadable on one side', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$queue = 'default';\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs\n\nclass Gene");
    $repo->commit('malformed content');

    $findings = null;
    $exception = null;

    try {
        $findings = analyzeQueueChange($repo);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($findings)->toBe([]);
});

it('does not swallow an unexpected Git/infrastructure failure from readFile()', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$queue = 'default';\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$queue = 'reports';\n}\n");
    $repo->commit('change queue');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new JobQueueChangedRule($vanishedReader))->analyze($diff);
})->throws(GitCommandFailedException::class);
