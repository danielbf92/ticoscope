<?php

use TicoScope\Php\EnvExampleParser;

it('parses simple KEY=value lines into a list of keys', function () {
    $source = "APP_NAME=Laravel\nAPP_ENV=local\nDB_HOST=127.0.0.1\n";

    expect((new EnvExampleParser())->parse($source))->toBe(['APP_NAME', 'APP_ENV', 'DB_HOST']);
});

it('ignores comment lines and blank lines', function () {
    $source = "# This is a comment\nAPP_NAME=Laravel\n\n\n# Another comment\nAPP_ENV=local\n";

    expect((new EnvExampleParser())->parse($source))->toBe(['APP_NAME', 'APP_ENV']);
});

it('handles an export-prefixed line', function () {
    expect((new EnvExampleParser())->parse('export APP_NAME=Laravel'))->toBe(['APP_NAME']);
});

it('ignores a non-matching line defensively', function () {
    $source = "not a valid line at all\nAPP_NAME=Laravel\n123INVALID=oops\n";

    expect((new EnvExampleParser())->parse($source))->toBe(['APP_NAME']);
});

it('returns an empty array for empty or whitespace-only source', function () {
    expect((new EnvExampleParser())->parse(''))->toBe([]);
    expect((new EnvExampleParser())->parse("\n\n   \n"))->toBe([]);
});
