<?php

use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;
use TicoScope\Findings\Finding;
use TicoScope\Findings\Severity;
use TicoScope\Reporting\ConsoleReporter;

function findingFixture(Severity $severity, string $ruleId, string $path, string $message): Finding
{
    return new Finding(
        ruleId: $ruleId,
        severity: $severity,
        file: new ChangedFile(path: $path, changeType: ChangeType::Modified),
        message: $message,
    );
}

it('renders the frozen worked example exactly', function () {
    $findings = [
        findingFixture(
            Severity::Critical,
            'migration.drop-column',
            'database/migrations/2026_09_12_add_status_to_orders.php',
            'Migration drops column "legacy_reference" on table "orders".',
        ),
        findingFixture(
            Severity::Warning,
            'config.env-without-default',
            'config/services.php',
            'New config key "services.reporting.endpoint" calls env() with no fallback.',
        ),
        findingFixture(
            Severity::Warning,
            'job.class-renamed',
            'app/Jobs/SyncInventory.php',
            'Job class renamed from SyncStock to SyncInventory.',
        ),
        findingFixture(
            Severity::Info,
            'composer.major-bump',
            'composer.lock',
            '"guzzlehttp/guzzle" bumped 6.x → 7.x.',
        ),
    ];

    $expected = implode("\n", [
        'CRITICAL (1)',
        '  ✗ database/migrations/2026_09_12_add_status_to_orders.php',
        '    [migration.drop-column] Migration drops column "legacy_reference" on table "orders".',
        '',
        'WARNING (2)',
        '  ! config/services.php',
        '    [config.env-without-default] New config key "services.reporting.endpoint" calls env() with no fallback.',
        '  ! app/Jobs/SyncInventory.php',
        '    [job.class-renamed] Job class renamed from SyncStock to SyncInventory.',
        '',
        'INFO (1)',
        '  · composer.lock',
        '    [composer.major-bump] "guzzlehttp/guzzle" bumped 6.x → 7.x.',
        '4 findings (1 critical, 2 warning, 1 info).',
    ]);

    expect((new ConsoleReporter())->report($findings))->toBe($expected);
});

it('groups CRITICAL before WARNING before INFO regardless of input order', function () {
    $findings = [
        findingFixture(Severity::Info, 'rule.info', 'a.php', 'info message'),
        findingFixture(Severity::Warning, 'rule.warning', 'b.php', 'warning message'),
        findingFixture(Severity::Critical, 'rule.critical', 'c.php', 'critical message'),
    ];

    $output = (new ConsoleReporter())->report($findings);

    $criticalPosition = strpos($output, 'CRITICAL');
    $warningPosition = strpos($output, 'WARNING');
    $infoPosition = strpos($output, 'INFO');

    expect($criticalPosition)->toBeLessThan($warningPosition);
    expect($warningPosition)->toBeLessThan($infoPosition);
});

it('preserves input order within a severity group', function () {
    $first = findingFixture(Severity::Warning, 'rule.first', 'first.php', 'first warning');
    $second = findingFixture(Severity::Critical, 'rule.only', 'only.php', 'the only critical');
    $third = findingFixture(Severity::Info, 'rule.info', 'info.php', 'the only info');
    $fourth = findingFixture(Severity::Warning, 'rule.second', 'second.php', 'second warning');

    $output = (new ConsoleReporter())->report([$first, $second, $third, $fourth]);

    expect(strpos($output, 'first.php'))->toBeLessThan(strpos($output, 'second.php'));
    expect($output)->toContain("WARNING (2)\n  ! first.php\n    [rule.first] first warning\n  ! second.php\n    [rule.second] second warning");
});

it('omits severity groups that have no findings', function () {
    $findings = [findingFixture(Severity::Info, 'rule.info', 'a.php', 'only info')];

    $output = (new ConsoleReporter())->report($findings);

    expect($output)->not->toContain('CRITICAL');
    expect($output)->not->toContain('WARNING');
    expect($output)->toContain('INFO (1)');
});

it('uses singular wording for exactly one finding', function () {
    $findings = [findingFixture(Severity::Info, 'rule.info', 'a.php', 'only info')];

    $output = (new ConsoleReporter())->report($findings);

    expect($output)->toEndWith('1 finding (1 info).');
});

it('only lists severities with a non-zero count in the summary', function () {
    $findings = [
        findingFixture(Severity::Warning, 'rule.a', 'a.php', 'a'),
        findingFixture(Severity::Warning, 'rule.b', 'b.php', 'b'),
    ];

    $output = (new ConsoleReporter())->report($findings);

    expect($output)->toEndWith('2 findings (2 warning).');
});

it('returns exactly "No findings." for an empty findings list', function () {
    expect((new ConsoleReporter())->report([]))->toBe('No findings.');
});
