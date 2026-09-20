<?php

use TicoScope\Diff\ChangeType;
use TicoScope\Git\GitDiffReader;
use TicoScope\Git\NotAGitRepositoryException;
use TicoScope\Git\UnknownRevisionException;
use TicoScope\Tests\Support\TemporaryGitRepository;

it('detects an added file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/ExampleJob.php', "<?php\n// job\n");
    $repo->commit('add job');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');

    expect($diff->changedFiles)->toHaveCount(1);
    expect($diff->changedFiles[0]->path)->toBe('app/Jobs/ExampleJob.php');
    expect($diff->changedFiles[0]->changeType)->toBe(ChangeType::Added);
    expect($diff->changedFiles[0]->originalPath)->toBeNull();
    expect($diff->changedFiles[0]->patch)->toContain('+// job');
});

it('detects a modified file and captures its patch', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('config/services.php', "line1\nline2\nline3\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('config/services.php', "line1\nCHANGED\nline3\n");
    $repo->commit('modify');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');

    expect($diff->changedFiles)->toHaveCount(1);
    $file = $diff->changedFiles[0];
    expect($file->path)->toBe('config/services.php');
    expect($file->changeType)->toBe(ChangeType::Modified);
    expect($file->originalPath)->toBeNull();
    expect($file->patch)->toContain('-line2');
    expect($file->patch)->toContain('+CHANGED');
});

it('detects a deleted file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Old.php', "<?php\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('app/Old.php');
    $repo->commit('delete');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');

    expect($diff->changedFiles)->toHaveCount(1);
    expect($diff->changedFiles[0]->path)->toBe('app/Old.php');
    expect($diff->changedFiles[0]->changeType)->toBe(ChangeType::Deleted);
    expect($diff->changedFiles[0]->originalPath)->toBeNull();
});

it('detects a renamed file and populates originalPath', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Old.php', "<?php\n// unchanged\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->renameFile('app/Old.php', 'app/New.php');
    $repo->commit('rename');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');

    expect($diff->changedFiles)->toHaveCount(1);
    $file = $diff->changedFiles[0];
    expect($file->changeType)->toBe(ChangeType::Renamed);
    expect($file->path)->toBe('app/New.php');
    expect($file->originalPath)->toBe('app/Old.php');
    expect($file->patch)->toContain('rename from app/Old.php');
    expect($file->patch)->toContain('rename to app/New.php');
});

it('captures the patch for a rename that also changes content', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Old.php', "line1\nline2\nline3\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->renameFile('app/Old.php', 'app/New.php');
    $repo->writeFile('app/New.php', "line1\nCHANGED\nline3\n");
    $repo->commit('rename and modify');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');

    expect($diff->changedFiles)->toHaveCount(1);
    $file = $diff->changedFiles[0];
    expect($file->changeType)->toBe(ChangeType::Renamed);
    expect($file->originalPath)->toBe('app/Old.php');
    expect($file->patch)->toContain('rename from app/Old.php');
    expect($file->patch)->toContain('-line2');
    expect($file->patch)->toContain('+CHANGED');
});

it('detects multiple changed files of different types in one comparison', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/ToDelete.php', "<?php\n// to delete\n");
    $repo->writeFile('app/ToRename.php', "<?php\n// to rename\n");
    $repo->writeFile('config/services.php', "line1\nline2\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Jobs/NewJob.php', "<?php\n// brand new job\n");
    $repo->deleteFile('app/ToDelete.php');
    $repo->renameFile('app/ToRename.php', 'app/Renamed.php');
    $repo->writeFile('config/services.php', "line1\nCHANGED\n");
    $repo->commit('multiple changes');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');

    expect($diff->changedFiles)->toHaveCount(4);

    $byPath = [];
    foreach ($diff->changedFiles as $file) {
        $byPath[$file->path] = $file;
    }

    expect($byPath['app/Jobs/NewJob.php']->changeType)->toBe(ChangeType::Added);
    expect($byPath['app/ToDelete.php']->changeType)->toBe(ChangeType::Deleted);
    expect($byPath['app/Renamed.php']->changeType)->toBe(ChangeType::Renamed);
    expect($byPath['app/Renamed.php']->originalPath)->toBe('app/ToRename.php');
    expect($byPath['config/services.php']->changeType)->toBe(ChangeType::Modified);
});

it('returns an empty diff when base and head are the same revision', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n");
    $repo->commit('base');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'main');

    expect($diff->changedFiles)->toBe([]);
    expect(count($diff))->toBe(0);
});

it('excludes changes merged into base after the head revision branched off', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/FeatureOnly.php', "<?php\n");
    $repo->commit('feature work');

    $repo->checkout('main');
    $repo->writeFile('app/MainOnly.php', "<?php\n// unrelated to this release\n");
    $repo->commit('unrelated main progress');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');

    $paths = array_map(fn ($file) => $file->path, $diff->changedFiles);

    expect($paths)->toContain('app/FeatureOnly.php');
    expect($paths)->not->toContain('app/MainOnly.php');
});

it('captures a filename containing spaces correctly', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/existing.php', "<?php\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/a file with spaces.php', "<?php\n// spaced\n");
    $repo->commit('add spaced file');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');

    expect($diff->changedFiles)->toHaveCount(1);
    expect($diff->changedFiles[0]->path)->toBe('app/a file with spaces.php');
});

it('throws when the base revision does not exist', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n");
    $repo->commit('base');

    (new GitDiffReader($repo->path()))->compare('does-not-exist', 'main');
})->throws(UnknownRevisionException::class);

it('throws when the working directory is not a Git repository', function () {
    $directory = sys_get_temp_dir().'/ticoscope-not-a-repo-'.uniqid();
    mkdir($directory);

    try {
        (new GitDiffReader($directory))->compare('main', 'HEAD');
    } finally {
        rmdir($directory);
    }
})->throws(NotAGitRepositoryException::class);

it('readFile() returns exact content for an existing path at a valid revision', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n\nclass Existing\n{\n}\n");
    $repo->commit('base');

    $content = (new GitDiffReader($repo->path()))->readFile('main', 'app/Existing.php');

    expect($content)->toBe("<?php\n\nclass Existing\n{\n}\n");
});

it('readFile() returns null for a missing path at a valid revision', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n");
    $repo->commit('base');

    $content = (new GitDiffReader($repo->path()))->readFile('main', 'app/DoesNotExist.php');

    expect($content)->toBeNull();
});

it('readFile() throws, rather than returning null, for an invalid revision', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n");
    $repo->commit('base');

    (new GitDiffReader($repo->path()))->readFile('does-not-exist', 'app/Existing.php');
})->throws(UnknownRevisionException::class);
