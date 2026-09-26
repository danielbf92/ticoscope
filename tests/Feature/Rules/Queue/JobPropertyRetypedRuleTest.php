<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Queue\JobPropertyRetypedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzePropertyRetyped(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new JobPropertyRetypedRule($gitDiffReader))->analyze($diff);
}

it('fires when a public property changes type', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$orderId;\n}\n");
    $repo->commit('retype property');

    $findings = analyzePropertyRetyped($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('queue.job-property-retyped');
    expect($findings[0]->reasonCode)->toBe('orderId');
    expect($findings[0]->message)->toContain('from int to string');
});

it('fires when an untyped property becomes typed', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public \$orderId;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('add a type');

    $findings = analyzePropertyRetyped($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->message)->toContain('from (untyped) to int');
});

it('fires when a promoted constructor property changes type', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public function __construct(public int \$orderId)\n    {\n    }\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public function __construct(public string \$orderId)\n    {\n    }\n}\n");
    $repo->commit('retype promoted property');

    $findings = analyzePropertyRetyped($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->reasonCode)->toBe('orderId');
});

it('does not fire when the type is unchanged', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n\n    public function handle(): void\n    {\n    }\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n\n    public function handle(): void\n    {\n        logger('running');\n    }\n}\n");
    $repo->commit('touch method body only');

    expect(analyzePropertyRetyped($repo))->toBe([]);
});

it('does not fire when the property was only removed', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('remove property');

    expect(analyzePropertyRetyped($repo))->toBe([]);
});

it('does not fire when a static property changes type, regardless of modifier order', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public static int \$defaultRetries = 3;\n    static public int \$legacyRetries = 3;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public static string \$defaultRetries = '3';\n    static public string \$legacyRetries = '3';\n}\n");
    $repo->commit('retype static properties');

    expect(analyzePropertyRetyped($repo))->toBe([]);
});

it('does not fire for a non-QueueJob candidate file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Services/GenerateReport.php', "<?php\n\nnamespace App\\Services;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/GenerateReport.php', "<?php\n\nnamespace App\\Services;\n\nclass GenerateReport\n{\n    public string \$orderId;\n}\n");
    $repo->commit('retype property on a non-job file');

    expect(analyzePropertyRetyped($repo))->toBe([]);
});

it('does not fire for a newly added Job class', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('add a new job');

    expect(analyzePropertyRetyped($repo))->toBe([]);
});

it('does not fire and does not throw when content is unreadable on one side', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs\n\nclass Gene");
    $repo->commit('malformed content');

    $findings = null;
    $exception = null;

    try {
        $findings = analyzePropertyRetyped($repo);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($findings)->toBe([]);
});

it('does not swallow an unexpected Git/infrastructure failure from readFile()', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public string \$orderId;\n}\n");
    $repo->commit('retype property');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new JobPropertyRetypedRule($vanishedReader))->analyze($diff);
})->throws(GitCommandFailedException::class);
