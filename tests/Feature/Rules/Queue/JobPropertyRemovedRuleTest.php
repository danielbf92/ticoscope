<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Queue\JobPropertyRemovedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzePropertyRemoval(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new JobPropertyRemovedRule($gitDiffReader))->analyze($diff);
}

it('fires when a public property is removed', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('remove property');

    $findings = analyzePropertyRemoval($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('queue.job-property-removed');
    expect($findings[0]->reasonCode)->toBe('orderId');
    expect($findings[0]->message)->toContain('$orderId');
    expect($findings[0]->message)->toContain('dynamic property');
});

it('fires when a promoted constructor property is removed', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public function __construct(public int \$orderId)\n    {\n    }\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public function __construct()\n    {\n    }\n}\n");
    $repo->commit('remove promoted property');

    $findings = analyzePropertyRemoval($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->reasonCode)->toBe('orderId');
});

it('does not fire when the property is unchanged', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n\n    public function handle(): void\n    {\n    }\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n\n    public function handle(): void\n    {\n        logger('running');\n    }\n}\n");
    $repo->commit('touch method body only');

    expect(analyzePropertyRemoval($repo))->toBe([]);
});

it('does not fire when a property is only added', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n    public string \$note;\n}\n");
    $repo->commit('add a property');

    expect(analyzePropertyRemoval($repo))->toBe([]);
});

it('does not fire when a static property is removed, regardless of modifier order', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public static string \$defaultConnection = 'redis';\n    static public string \$legacyDefault = 'old';\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('remove static properties');

    expect(analyzePropertyRemoval($repo))->toBe([]);
});

it('does not fire for a non-QueueJob candidate file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Services/GenerateReport.php', "<?php\n\nnamespace App\\Services;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/GenerateReport.php', "<?php\n\nnamespace App\\Services;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('remove property on a non-job file');

    expect(analyzePropertyRemoval($repo))->toBe([]);
});

it('does not fire for a newly added Job class', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('add a new job');

    expect(analyzePropertyRemoval($repo))->toBe([]);
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
        $findings = analyzePropertyRemoval($repo);
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
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('remove property');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new JobPropertyRemovedRule($vanishedReader))->analyze($diff);
})->throws(GitCommandFailedException::class);

it('fires alongside JobFqcnChangedRule when a rename and a property removal happen together', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public int \$orderId;\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs\\Reporting;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('change namespace and remove property');

    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare('main', 'feature');

    $propertyFindings = (new JobPropertyRemovedRule($gitDiffReader))->analyze($diff);
    $identityFindings = (new TicoScope\Rules\Queue\JobFqcnChangedRule($gitDiffReader))->analyze($diff);

    expect($propertyFindings)->toHaveCount(1);
    expect($propertyFindings[0]->ruleId)->toBe('queue.job-property-removed');
    expect($identityFindings)->toHaveCount(1);
    expect($identityFindings[0]->ruleId)->toBe('queue.job-fqcn-changed');
});
