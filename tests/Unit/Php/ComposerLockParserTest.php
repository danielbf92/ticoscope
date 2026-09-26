<?php

use TicoScope\Php\ComposerLockParser;

it('parses a well-formed lock file into a combined name to version map', function () {
    $source = json_encode([
        'packages' => [
            ['name' => 'guzzlehttp/guzzle', 'version' => '7.8.1'],
            ['name' => 'brick/math', 'version' => '0.11.0'],
        ],
        'packages-dev' => [
            ['name' => 'pestphp/pest', 'version' => 'v3.0.0'],
        ],
    ]);

    $versions = (new ComposerLockParser())->parse($source);

    expect($versions)->toBe([
        'guzzlehttp/guzzle' => '7.8.1',
        'brick/math' => '0.11.0',
        'pestphp/pest' => 'v3.0.0',
    ]);
});

it('returns null for malformed JSON', function () {
    expect((new ComposerLockParser())->parse('{"packages": [not valid json'))->toBeNull();
});

it('returns an empty map when packages and packages-dev are absent', function () {
    $source = json_encode(['content-hash' => 'abc123']);

    expect((new ComposerLockParser())->parse($source))->toBe([]);
});

it('returns an empty map when packages and packages-dev are empty arrays', function () {
    $source = json_encode(['packages' => [], 'packages-dev' => []]);

    expect((new ComposerLockParser())->parse($source))->toBe([]);
});

it('preserves a v-prefixed version string verbatim, without stripping it', function () {
    $source = json_encode([
        'packages' => [
            ['name' => 'dflydev/dot-access-data', 'version' => 'v3.0.3'],
        ],
    ]);

    $versions = (new ComposerLockParser())->parse($source);

    expect($versions['dflydev/dot-access-data'])->toBe('v3.0.3');
});

it('resolves a packages/packages-dev name conflict in favor of packages-dev', function () {
    $source = json_encode([
        'packages' => [
            ['name' => 'shared/package', 'version' => '1.0.0'],
        ],
        'packages-dev' => [
            ['name' => 'shared/package', 'version' => '2.0.0'],
        ],
    ]);

    $versions = (new ComposerLockParser())->parse($source);

    expect($versions['shared/package'])->toBe('2.0.0');
});
