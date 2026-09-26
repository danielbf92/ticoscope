<?php

use TicoScope\Php\EnvCallExtractor;

it('extracts a single env() call variable name', function () {
    expect((new EnvCallExtractor())->extract("env('PAYMENT_GATEWAY_KEY');"))->toBe(['PAYMENT_GATEWAY_KEY']);
});

it('extracts the variable name regardless of a fallback being present', function () {
    expect((new EnvCallExtractor())->extract("env('PAYMENT_GATEWAY_KEY', 'default-value');"))
        ->toBe(['PAYMENT_GATEWAY_KEY']);
});

it('extracts multiple distinct calls from one fragment', function () {
    $source = "\$a = env('FIRST_VAR');\n\$b = env('SECOND_VAR', 'fallback');\n";

    expect((new EnvCallExtractor())->extract($source))->toBe(['FIRST_VAR', 'SECOND_VAR']);
});

it('returns an empty array when the variable argument is not a string literal', function () {
    expect((new EnvCallExtractor())->extract('env($dynamicKey);'))->toBe([]);
});

it('returns an empty array for a fragment with no env() calls', function () {
    expect((new EnvCallExtractor())->extract('$x = 1 + 2;'))->toBe([]);
});

it('recognizes ENV(...) case-insensitively', function () {
    expect((new EnvCallExtractor())->extract("ENV('SHOUTY_VAR');"))->toBe(['SHOUTY_VAR']);
});

it('does not throw for malformed or incomplete fragment content', function () {
    $result = null;
    $exception = null;

    try {
        $result = (new EnvCallExtractor())->extract("env('UNCLOSED");
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($result)->toBe([]);
});
