<?php

use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;

it('holds the path, change type, patch, and original path it was constructed with', function () {
    $file = new ChangedFile(
        path: 'app/Jobs/SyncInventory.php',
        changeType: ChangeType::Renamed,
        patch: "diff --git a/app/Jobs/SyncStock.php b/app/Jobs/SyncInventory.php\n...",
        originalPath: 'app/Jobs/SyncStock.php',
    );

    expect($file->path)->toBe('app/Jobs/SyncInventory.php');
    expect($file->changeType)->toBe(ChangeType::Renamed);
    expect($file->patch)->toContain('diff --git');
    expect($file->originalPath)->toBe('app/Jobs/SyncStock.php');
});

it('defaults patch and originalPath to null', function () {
    $file = new ChangedFile(path: 'config/services.php', changeType: ChangeType::Added);

    expect($file->patch)->toBeNull();
    expect($file->originalPath)->toBeNull();
});
