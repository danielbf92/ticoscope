<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Queue\JobConnectionChangedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzeConnectionChange(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new JobConnectionChangedRule($gitDiffReader))->analyze($diff);
}

it('fires when the declared connection literal changes', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = 'redis';\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = 'sqs';\n}\n");
    $repo->commit('change connection');

    $findings = analyzeConnectionChange($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('queue.job-connection-changed');
    expect($findings[0]->reasonCode)->toBe('connection');
    expect($findings[0]->message)->toContain("from 'redis' to 'sqs'");
});

it('does not fire when the connection is unchanged', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = 'redis';\n\n    public function handle(): void\n    {\n    }\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = 'redis';\n\n    public function handle(): void\n    {\n        logger('running');\n    }\n}\n");
    $repo->commit('touch method body only');

    expect(analyzeConnectionChange($repo))->toBe([]);
});

it('does not fire when the old side has a non-literal default', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = config('queue.default');\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = 'sqs';\n}\n");
    $repo->commit('change connection');

    expect(analyzeConnectionChange($repo))->toBe([]);
});

it('does not fire when the new side has a non-literal default', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = 'redis';\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = config('queue.default');\n}\n");
    $repo->commit('change connection to a non-literal');

    expect(analyzeConnectionChange($repo))->toBe([]);
});

it('does not fire when the connection is not declared on either side', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$orderId;\n}\n");
    $repo->commit('unrelated retype');

    expect(analyzeConnectionChange($repo))->toBe([]);
});

it('does not fire for a non-QueueJob candidate file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Services/GenerateReport.php', "<?php\n\nnamespace App\\Services;\n\nclass GenerateReport\n{\n    public string \$connection = 'redis';\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/GenerateReport.php', "<?php\n\nnamespace App\\Services;\n\nclass GenerateReport\n{\n    public string \$connection = 'sqs';\n}\n");
    $repo->commit('change connection on a non-job file');

    expect(analyzeConnectionChange($repo))->toBe([]);
});

it('does not fire for a newly added Job class', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = 'redis';\n}\n");
    $repo->commit('add a new job');

    expect(analyzeConnectionChange($repo))->toBe([]);
});

it('does not fire and does not throw when content is unreadable on one side', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = 'redis';\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs\n\nclass Gene");
    $repo->commit('malformed content');

    $findings = null;
    $exception = null;

    try {
        $findings = analyzeConnectionChange($repo);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($findings)->toBe([]);
});

it('does not swallow an unexpected Git/infrastructure failure from readFile()', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = 'redis';\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = 'sqs';\n}\n");
    $repo->commit('change connection');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new JobConnectionChangedRule($vanishedReader))->analyze($diff);
})->throws(GitCommandFailedException::class);

it('fires alongside JobQueueChangedRule when both connection and queue change together', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = 'redis';\n    public string \$queue = 'default';\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$connection = 'sqs';\n    public string \$queue = 'reports';\n}\n");
    $repo->commit('change connection and queue');

    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare('main', 'feature');

    $connectionFindings = (new JobConnectionChangedRule($gitDiffReader))->analyze($diff);
    $queueFindings = (new TicoScope\Rules\Queue\JobQueueChangedRule($gitDiffReader))->analyze($diff);

    expect($connectionFindings)->toHaveCount(1);
    expect($connectionFindings[0]->ruleId)->toBe('queue.job-connection-changed');
    expect($queueFindings)->toHaveCount(1);
    expect($queueFindings[0]->ruleId)->toBe('queue.job-queue-changed');
});
