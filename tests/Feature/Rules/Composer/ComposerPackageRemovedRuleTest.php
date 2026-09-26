<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Composer\ComposerPackageRemovedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzePackageRemoved(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new ComposerPackageRemovedRule($gitDiffReader))->analyze($diff);
}

function removedRuleLockFixture(array $packages): string
{
    return json_encode(['packages' => $packages, 'packages-dev' => []], JSON_PRETTY_PRINT);
}

it('fires when a package present in the old lock file is absent from the new one', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', removedRuleLockFixture([
        ['name' => 'nesbot/carbon', 'version' => '2.72.0'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', removedRuleLockFixture([]));
    $repo->commit('remove nesbot/carbon');

    $findings = analyzePackageRemoved($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('composer.package-removed');
    expect($findings[0]->reasonCode)->toBe('nesbot/carbon');
    expect($findings[0]->message)->toBe(
        'Dependency "nesbot/carbon" (was 2.72.0) has been removed from composer.lock. Code or configuration '.
        'that still depends on this package may fail after deployment — confirm it is no longer required.',
    );
});

it('does not fire for a package that is merely version-bumped, still present', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', removedRuleLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '6.5.2'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', removedRuleLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('bump, not remove');

    expect(analyzePackageRemoved($repo))->toBe([]);
});

it('does not fire for a newly added package', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', removedRuleLockFixture([]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', removedRuleLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('add a brand new dependency');

    expect(analyzePackageRemoved($repo))->toBe([]);
});

it('does not fire when composer.lock is newly Added', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', removedRuleLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('add composer.lock for the first time');

    expect(analyzePackageRemoved($repo))->toBe([]);
});

it('does not fire when composer.lock is Deleted', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', removedRuleLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('composer.lock');
    $repo->commit('delete composer.lock');

    expect(analyzePackageRemoved($repo))->toBe([]);
});

it('does not fire and does not throw for malformed composer.lock content', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', '{"packages": [not valid json');
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', removedRuleLockFixture([]));
    $repo->commit('replace malformed content with valid content');

    $findings = null;
    $exception = null;

    try {
        $findings = analyzePackageRemoved($repo);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($findings)->toBe([]);
});

it('does not swallow an unexpected Git/infrastructure failure from readFile()', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', removedRuleLockFixture([
        ['name' => 'nesbot/carbon', 'version' => '2.72.0'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', removedRuleLockFixture([]));
    $repo->commit('remove nesbot/carbon');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new ComposerPackageRemovedRule($vanishedReader))->analyze($diff);
})->throws(GitCommandFailedException::class);

it('fires once per package when multiple packages are removed', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', removedRuleLockFixture([
        ['name' => 'nesbot/carbon', 'version' => '2.72.0'],
        ['name' => 'acme/legacy', 'version' => '1.0.0'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', removedRuleLockFixture([]));
    $repo->commit('remove both packages');

    $findings = analyzePackageRemoved($repo);

    expect($findings)->toHaveCount(2);
    $reasonCodes = array_map(fn ($f) => $f->reasonCode, $findings);
    expect($reasonCodes)->toEqualCanonicalizing(['nesbot/carbon', 'acme/legacy']);
});
