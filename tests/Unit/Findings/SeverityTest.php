<?php

use TicoScope\Findings\Severity;

it('backs each case with its stable string value', function () {
    expect(Severity::Info->value)->toBe('info');
    expect(Severity::Warning->value)->toBe('warning');
    expect(Severity::Critical->value)->toBe('critical');
});

it('resolves cases from their string value', function () {
    expect(Severity::from('warning'))->toBe(Severity::Warning);
});

it('meets() reports whether a severity is at or above a threshold', function (Severity $severity, Severity $threshold, bool $expected) {
    expect($severity->meets($threshold))->toBe($expected);
})->with([
    'info meets info' => [Severity::Info, Severity::Info, true],
    'info does not meet warning' => [Severity::Info, Severity::Warning, false],
    'info does not meet critical' => [Severity::Info, Severity::Critical, false],
    'warning meets info' => [Severity::Warning, Severity::Info, true],
    'warning meets warning' => [Severity::Warning, Severity::Warning, true],
    'warning does not meet critical' => [Severity::Warning, Severity::Critical, false],
    'critical meets info' => [Severity::Critical, Severity::Info, true],
    'critical meets warning' => [Severity::Critical, Severity::Warning, true],
    'critical meets critical' => [Severity::Critical, Severity::Critical, true],
]);
