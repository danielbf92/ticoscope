<?php

use TicoScope\Diff\AddedLineExtractor;
use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;

function changedFileWithPatch(?string $patch): ChangedFile
{
    return new ChangedFile(path: 'config/services.php', changeType: ChangeType::Modified, patch: $patch);
}

it('extracts a single added run', function () {
    $patch = <<<'PATCH'
        diff --git a/config/services.php b/config/services.php
        index abc..def 100644
        --- a/config/services.php
        +++ b/config/services.php
        @@ -1,3 +1,4 @@
         line1
        +added line
         line2
         line3
        PATCH;

    $runs = (new AddedLineExtractor())->extract(changedFileWithPatch($patch));

    expect($runs)->toBe(['added line']);
});

it('extracts multiple separated added runs within one hunk', function () {
    $patch = <<<'PATCH'
        diff --git a/config/services.php b/config/services.php
        index abc..def 100644
        --- a/config/services.php
        +++ b/config/services.php
        @@ -1,4 +1,7 @@
         line1
        +added A
         line2
        +added B
        +added B2
         line3
        PATCH;

    $runs = (new AddedLineExtractor())->extract(changedFileWithPatch($patch));

    expect($runs)->toBe(['added A', "added B\nadded B2"]);
});

it('extracts a run from each hunk', function () {
    $patch = <<<'PATCH'
        diff --git a/config/services.php b/config/services.php
        index abc..def 100644
        --- a/config/services.php
        +++ b/config/services.php
        @@ -1,2 +1,3 @@
         line1
        +first hunk addition
         line2
        @@ -10,2 +11,3 @@
         line10
        +second hunk addition
         line11
        PATCH;

    $runs = (new AddedLineExtractor())->extract(changedFileWithPatch($patch));

    expect($runs)->toBe(['first hunk addition', 'second hunk addition']);
});

it('returns no runs for a pure deletion', function () {
    $patch = <<<'PATCH'
        diff --git a/app/Old.php b/app/Old.php
        deleted file mode 100644
        index abc..0000000
        --- a/app/Old.php
        +++ /dev/null
        @@ -1,2 +0,0 @@
        -line1
        -line2
        PATCH;

    $runs = (new AddedLineExtractor())->extract(changedFileWithPatch($patch));

    expect($runs)->toBe([]);
});

it('never treats the +++ file header as source', function () {
    $patch = <<<'PATCH'
        diff --git a/config/services.php b/config/services.php
        index abc..def 100644
        --- a/config/services.php
        +++ b/config/services.php
        @@ -1,1 +1,2 @@
         line1
        +added line
        PATCH;

    $runs = (new AddedLineExtractor())->extract(changedFileWithPatch($patch));

    expect($runs)->toBe(['added line']);
    expect(implode('', $runs))->not->toContain('b/config/services.php');
});

it('returns no runs for a rename-only patch with no hunk', function () {
    $patch = <<<'PATCH'
        diff --git a/app/Old.php b/app/New.php
        similarity index 100%
        rename from app/Old.php
        rename to app/New.php
        PATCH;

    $runs = (new AddedLineExtractor())->extract(changedFileWithPatch($patch));

    expect($runs)->toBe([]);
});

it('returns no runs for an empty or null patch', function () {
    expect((new AddedLineExtractor())->extract(changedFileWithPatch('')))->toBe([]);
    expect((new AddedLineExtractor())->extract(changedFileWithPatch(null)))->toBe([]);
});
