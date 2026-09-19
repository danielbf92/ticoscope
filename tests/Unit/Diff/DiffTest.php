<?php

use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Diff\Diff;

it('holds the base revision, head revision, and changed files it was constructed with', function () {
    $files = [
        new ChangedFile('composer.lock', ChangeType::Modified),
        new ChangedFile('config/services.php', ChangeType::Added),
    ];

    $diff = new Diff(baseRevision: 'main', headRevision: 'HEAD', changedFiles: $files);

    expect($diff->baseRevision)->toBe('main');
    expect($diff->headRevision)->toBe('HEAD');
    expect($diff->changedFiles)->toBe($files);
});

it('is countable by its number of changed files', function () {
    $diff = new Diff('main', 'HEAD', [
        new ChangedFile('a.php', ChangeType::Added),
        new ChangedFile('b.php', ChangeType::Deleted),
    ]);

    expect(count($diff))->toBe(2);
});

it('defaults to an empty set of changed files', function () {
    $diff = new Diff('main', 'HEAD');

    expect($diff->changedFiles)->toBe([]);
    expect(count($diff))->toBe(0);
});
