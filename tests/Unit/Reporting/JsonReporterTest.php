<?php

use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Findings\Finding;
use TicoScope\Findings\Severity;
use TicoScope\Reporting\JsonReporter;

function jsonFindingFixture(Severity $severity, string $ruleId, string $path, string $message, ?string $reasonCode = null): Finding
{
    return new Finding(
        ruleId: $ruleId,
        severity: $severity,
        file: new ChangedFile(path: $path, changeType: ChangeType::Modified),
        message: $message,
        reasonCode: $reasonCode,
    );
}

it('renders the frozen worked example exactly', function () {
    $findings = [
        jsonFindingFixture(
            Severity::Critical,
            'migration.column-dropped',
            'database/migrations/2026_09_12_add_status_to_orders.php',
            'Migration drops column "legacy_reference" on table "orders". This is a destructive, typically '.
            'irreversible operation. Confirm a backup/rollback plan exists.',
            'legacy_reference',
        ),
    ];

    $decoded = json_decode((new JsonReporter())->report($findings), associative: true);

    expect($decoded)->toBe([
        'schema_version' => '1',
        'summary' => [
            'total' => 1,
            'critical' => 1,
            'warning' => 0,
            'info' => 0,
        ],
        'findings' => [
            [
                'rule_id' => 'migration.column-dropped',
                'severity' => 'critical',
                'file' => 'database/migrations/2026_09_12_add_status_to_orders.php',
                'message' => 'Migration drops column "legacy_reference" on table "orders". This is a destructive, '.
                    'typically irreversible operation. Confirm a backup/rollback plan exists.',
                'reason_code' => 'legacy_reference',
            ],
        ],
    ]);
});

it('produces a valid, zeroed-out document for an empty findings list', function () {
    $decoded = json_decode((new JsonReporter())->report([]), associative: true);

    expect($decoded)->toBe([
        'schema_version' => '1',
        'summary' => [
            'total' => 0,
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
        ],
        'findings' => [],
    ]);
});

it('keeps reason_code present and null when the Finding has none', function () {
    $findings = [jsonFindingFixture(Severity::Warning, 'rule.a', 'a.php', 'message a')];

    $decoded = json_decode((new JsonReporter())->report($findings), associative: true);

    expect($decoded['findings'][0])->toHaveKey('reason_code');
    expect($decoded['findings'][0]['reason_code'])->toBeNull();
});

it('includes every summary count even when a severity has zero findings', function () {
    $findings = [
        jsonFindingFixture(Severity::Warning, 'rule.a', 'a.php', 'a'),
        jsonFindingFixture(Severity::Warning, 'rule.b', 'b.php', 'b'),
    ];

    $decoded = json_decode((new JsonReporter())->report($findings), associative: true);

    expect($decoded['summary'])->toBe([
        'total' => 2,
        'critical' => 0,
        'warning' => 2,
        'info' => 0,
    ]);
});

it('preserves input order in the findings array regardless of severity', function () {
    $findings = [
        jsonFindingFixture(Severity::Warning, 'rule.first', 'first.php', 'first'),
        jsonFindingFixture(Severity::Critical, 'rule.second', 'second.php', 'second'),
        jsonFindingFixture(Severity::Info, 'rule.third', 'third.php', 'third'),
    ];

    $decoded = json_decode((new JsonReporter())->report($findings), associative: true);

    expect(array_column($decoded['findings'], 'rule_id'))->toBe(['rule.first', 'rule.second', 'rule.third']);
});
