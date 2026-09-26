<?php

namespace TicoScope\Reporting;

use TicoScope\Findings\Finding;
use TicoScope\Findings\Severity;

/**
 * Human-readable terminal output, grouped by severity (CRITICAL, then
 * WARNING, then INFO — always in that fixed order, regardless of input
 * order). Within a group, findings keep the order they arrived in.
 *
 * Display order is deliberately this class's own private detail (a fixed
 * list of the three cases), not a public Severity API — the only
 * severity-ordering concept Severity itself exposes is meets(), used for
 * --fail-on gating, a different concern from display grouping.
 */
final class ConsoleReporter implements Reporter
{
    /**
     * @var Severity[]
     */
    private const DISPLAY_ORDER = [Severity::Critical, Severity::Warning, Severity::Info];

    private const MARKERS = [
        'critical' => '✗',
        'warning' => '!',
        'info' => '·',
    ];

    public function report(array $findings): string
    {
        if ($findings === []) {
            return 'No findings.';
        }

        $lines = [];

        foreach (self::DISPLAY_ORDER as $severity) {
            $group = $this->findingsOfSeverity($findings, $severity);

            if ($group === []) {
                continue;
            }

            $lines[] = sprintf('%s (%d)', strtoupper($severity->value), count($group));

            foreach ($group as $finding) {
                $lines[] = sprintf('  %s %s', self::MARKERS[$severity->value], $finding->file->path);
                $lines[] = sprintf('    [%s] %s', $finding->ruleId, $finding->message);
            }

            $lines[] = '';
        }

        array_pop($lines);

        $lines[] = $this->summaryLine($findings);

        return implode("\n", $lines);
    }

    /**
     * @param Finding[] $findings
     * @return Finding[]
     */
    private function findingsOfSeverity(array $findings, Severity $severity): array
    {
        return array_values(array_filter(
            $findings,
            fn (Finding $finding): bool => $finding->severity === $severity,
        ));
    }

    /**
     * @param Finding[] $findings
     */
    private function summaryLine(array $findings): string
    {
        $total = count($findings);
        $parts = [];

        foreach (self::DISPLAY_ORDER as $severity) {
            $count = count($this->findingsOfSeverity($findings, $severity));

            if ($count > 0) {
                $parts[] = sprintf('%d %s', $count, $severity->value);
            }
        }

        return sprintf('%d finding%s (%s).', $total, $total === 1 ? '' : 's', implode(', ', $parts));
    }
}
