<?php

use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Findings\Finding;
use TicoScope\Findings\Severity;

it('holds the rule id, severity, file, message, and reason code it was constructed with', function () {
    $file = new ChangedFile('database/migrations/2026_09_12_add_status_to_orders.php', ChangeType::Modified);

    $finding = new Finding(
        ruleId: 'migration.drop-column',
        severity: Severity::Critical,
        file: $file,
        message: 'Migration drops column "legacy_reference" on table "orders".',
        reasonCode: 'drop-column',
    );

    expect($finding->ruleId)->toBe('migration.drop-column');
    expect($finding->severity)->toBe(Severity::Critical);
    expect($finding->file)->toBe($file);
    expect($finding->message)->toBe('Migration drops column "legacy_reference" on table "orders".');
    expect($finding->reasonCode)->toBe('drop-column');
});

it('defaults reason code to null', function () {
    $finding = new Finding(
        ruleId: 'composer.major-bump',
        severity: Severity::Info,
        file: new ChangedFile('composer.lock', ChangeType::Modified),
        message: '"guzzlehttp/guzzle" bumped 6.x to 7.x.',
    );

    expect($finding->reasonCode)->toBeNull();
});
