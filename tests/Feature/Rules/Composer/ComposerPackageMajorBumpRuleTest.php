<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Composer\ComposerPackageMajorBumpRule;
use TicoScope\Rules\Composer\ComposerPackageRemovedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzeMajorBump(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new ComposerPackageMajorBumpRule($gitDiffReader))->analyze($diff);
}

function composerLockFixture(array $packages, array $packagesDev = []): string
{
    return json_encode(['packages' => $packages, 'packages-dev' => $packagesDev], JSON_PRETTY_PRINT);
}

it('fires when a package major version increases', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '6.5.2'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('bump guzzle to 7.x');

    $findings = analyzeMajorBump($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('composer.package-major-bump');
    expect($findings[0]->reasonCode)->toBe('guzzlehttp/guzzle');
    expect($findings[0]->message)->toBe(
        'Dependency "guzzlehttp/guzzle" major version changed from 6.5.2 to 7.1.0. Review the package\'s '.
        'changelog for breaking changes before deploying.',
    );
});

it('fires when a package major version decreases', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '6.5.2'],
    ]));
    $repo->commit('downgrade guzzle to 6.x');

    $findings = analyzeMajorBump($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->reasonCode)->toBe('guzzlehttp/guzzle');
});

it('does not fire for a minor/patch-only change', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.9.3'],
    ]));
    $repo->commit('minor/patch bump only');

    expect(analyzeMajorBump($repo))->toBe([]);
});

it('does not fire when the version is unchanged', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
        ['name' => 'brick/math', 'version' => '0.11.0'],
    ]));
    $repo->commit('add an unrelated package, guzzle untouched');

    expect(analyzeMajorBump($repo))->toBe([]);
});

it('does not fire for a newly added package', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', composerLockFixture([]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('add a brand new dependency');

    expect(analyzeMajorBump($repo))->toBe([]);
});

it('does not fire for a removed package', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', composerLockFixture([]));
    $repo->commit('remove the dependency entirely');

    expect(analyzeMajorBump($repo))->toBe([]);
});

it('does not fire when either version does not match the recognized v?digits. pattern', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'acme/dev-package', 'version' => 'dev-main'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'acme/dev-package', 'version' => 'dev-feature-branch'],
    ]));
    $repo->commit('change the tracked dev branch');

    expect(analyzeMajorBump($repo))->toBe([]);
});

it('does not fire for a non-composer.lock file with the same JSON shape', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('subdir/composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '6.5.2'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('subdir/composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('bump inside a non-root composer.lock');

    expect(analyzeMajorBump($repo))->toBe([]);
});

it('does not fire when composer.lock is newly Added', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('add composer.lock for the first time');

    expect(analyzeMajorBump($repo))->toBe([]);
});

it('does not fire when composer.lock is Deleted', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('composer.lock');
    $repo->commit('delete composer.lock');

    expect(analyzeMajorBump($repo))->toBe([]);
});

it('does not fire and does not throw for malformed composer.lock content', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', '{"packages": [not valid json');
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('replace malformed content with valid content');

    $findings = null;
    $exception = null;

    try {
        $findings = analyzeMajorBump($repo);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($findings)->toBe([]);
});

it('does not swallow an unexpected Git/infrastructure failure from readFile()', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '6.5.2'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('bump guzzle to 7.x');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new ComposerPackageMajorBumpRule($vanishedReader))->analyze($diff);
})->throws(GitCommandFailedException::class);

it('fires once per package when multiple packages are major-bumped in one commit', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '6.5.2'],
        ['name' => 'acme/widgets', 'version' => '1.2.0'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
        ['name' => 'acme/widgets', 'version' => '2.0.0'],
    ]));
    $repo->commit('bump two packages');

    $findings = analyzeMajorBump($repo);

    expect($findings)->toHaveCount(2);
    $reasonCodes = array_map(fn ($f) => $f->reasonCode, $findings);
    expect($reasonCodes)->toEqualCanonicalizing(['guzzlehttp/guzzle', 'acme/widgets']);
});

it('fires alongside ComposerPackageRemovedRule when one commit both bumps and removes a package', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '6.5.2'],
        ['name' => 'acme/legacy', 'version' => '1.0.0'],
    ]));
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('composer.lock', composerLockFixture([
        ['name' => 'guzzlehttp/guzzle', 'version' => '7.1.0'],
    ]));
    $repo->commit('bump guzzle, remove acme/legacy');

    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare('main', 'feature');

    $bumpFindings = (new ComposerPackageMajorBumpRule($gitDiffReader))->analyze($diff);
    $removedFindings = (new ComposerPackageRemovedRule($gitDiffReader))->analyze($diff);

    expect($bumpFindings)->toHaveCount(1);
    expect($bumpFindings[0]->reasonCode)->toBe('guzzlehttp/guzzle');
    expect($removedFindings)->toHaveCount(1);
    expect($removedFindings[0]->reasonCode)->toBe('acme/legacy');
});
