<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Queue\JobFqcnChangedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzeFqcnChange(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new JobFqcnChangedRule($gitDiffReader))->analyze($diff);
}

it('does not fire when a file is renamed but the FQCN is unchanged', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->renameFile('app/Jobs/GenerateReport.php', 'app/Jobs/Reports/GenerateReport.php');
    $repo->commit('move within app/Jobs, same FQCN');

    expect(analyzeFqcnChange($repo))->toBe([]);
});

it('fires when the namespace changes on an otherwise Modified file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs\\Reporting;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('change namespace only');

    $findings = analyzeFqcnChange($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('queue.job-fqcn-changed');
    expect($findings[0]->reasonCode)->toBe('App\Jobs\GenerateReport');
    expect($findings[0]->message)->toContain('App\Jobs\GenerateReport');
    expect($findings[0]->message)->toContain('App\Jobs\Reporting\GenerateReport');
    expect($findings[0]->message)->toContain('may no longer deserialize or resolve correctly');
});

it('fires when the class identifier changes on an otherwise Modified file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass BuildReport\n{\n}\n");
    $repo->commit('change class name only');

    $findings = analyzeFqcnChange($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->reasonCode)->toBe('App\Jobs\GenerateReport');
    expect($findings[0]->message)->toContain('App\Jobs\BuildReport');
});

it('fires when a rename is combined with a namespace change', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->renameFile('app/Jobs/GenerateReport.php', 'app/Jobs/Reports/GenerateReport.php');
    $repo->writeFile('app/Jobs/Reports/GenerateReport.php', "<?php\n\nnamespace App\\Jobs\\Reports;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('rename and change namespace');

    $findings = analyzeFqcnChange($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->reasonCode)->toBe('App\Jobs\GenerateReport');
    expect($findings[0]->message)->toContain('App\Jobs\Reports\GenerateReport');
});

it('fires when a rename is combined with a class name change', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->renameFile('app/Jobs/GenerateReport.php', 'app/Jobs/BuildReport.php');
    $repo->writeFile('app/Jobs/BuildReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass BuildReport\n{\n}\n");
    $repo->commit('rename and change class name');

    $findings = analyzeFqcnChange($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->reasonCode)->toBe('App\Jobs\GenerateReport');
    expect($findings[0]->message)->toContain('App\Jobs\BuildReport');
});

it('does not fire when unrelated Job content changes', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public function handle(): void\n    {\n    }\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n    public function handle(): void\n    {\n        logger('running');\n    }\n}\n");
    $repo->commit('change method body only');

    expect(analyzeFqcnChange($repo))->toBe([]);
});

it('does not fire for a newly added Job class', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('add a new job');

    expect(analyzeFqcnChange($repo))->toBe([]);
});

it('does not fire for a non-QueueJob candidate file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Services/GenerateReport.php', "<?php\n\nnamespace App\\Services;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/GenerateReport.php', "<?php\n\nnamespace App\\Services\\Reporting;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('change namespace of a non-job file');

    expect(analyzeFqcnChange($repo))->toBe([]);
});

it('fires when a Job candidate moves out of app/Jobs and its FQCN changes (Scenario A)', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->renameFile('app/Jobs/GenerateReport.php', 'app/Legacy/GenerateReport.php');
    $repo->writeFile('app/Legacy/GenerateReport.php', "<?php\n\nnamespace App\\Legacy;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('move out of app/Jobs with a namespace change');

    $findings = analyzeFqcnChange($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->reasonCode)->toBe('App\Jobs\GenerateReport');
    expect($findings[0]->message)->toContain('App\Legacy\GenerateReport');
});

it('does not fire when a non-Job candidate moves into app/Jobs, even with an FQCN change (Scenario B)', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Services/GenerateReport.php', "<?php\n\nnamespace App\\Services;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->renameFile('app/Services/GenerateReport.php', 'app/Jobs/GenerateReport.php');
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('move into app/Jobs with a namespace change');

    expect(analyzeFqcnChange($repo))->toBe([]);
});

it('does not fire and does not throw when content is unreadable on one side', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    // Malformed content: no parsable class declaration on the new side.
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs\n\nclass Gene");
    $repo->commit('malformed content');

    $findings = null;
    $exception = null;

    try {
        $findings = analyzeFqcnChange($repo);
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
    $repo->writeFile('app/Jobs/GenerateReport.php', "<?php\n\nnamespace App\\Jobs\\Reporting;\n\nclass GenerateReport\n{\n}\n");
    $repo->commit('change namespace');

    // The Diff itself is computed successfully from the real repo, but the
    // reader handed to the rule points at a working directory that no
    // longer exists — readFile() must throw (proven separately in
    // GitDiffReaderTest), and this asserts the rule lets that exception
    // propagate rather than treating it as "no finding".
    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new JobFqcnChangedRule($vanishedReader))->analyze($diff);
})->throws(GitCommandFailedException::class);
