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
