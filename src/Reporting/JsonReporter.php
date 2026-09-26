<?php

namespace TicoScope\Reporting;

use TicoScope\Findings\Finding;

/**
 * Machine-readable output for CI/tooling consumption. Field names and the
 * `schema_version` value are a frozen contract (VISION §10) — do not rename
 * or restructure without bumping `schema_version`.
 */
final class JsonReporter implements Reporter
{
    private const SCHEMA_VERSION = '1';

    /**
     * @param Finding[] $findings
     */
    public function report(array $findings): string
    {
        $document = [
            'schema_version' => self::SCHEMA_VERSION,
            'summary' => $this->summarize($findings),
            'findings' => array_map($this->toArray(...), $findings),
        ];

        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $json === false ? '{"schema_version":"1","summary":{"total":0,"critical":0,"warning":0,"info":0},"findings":[]}' : $json;
    }

    /**
     * @param Finding[] $findings
     * @return array{total: int, critical: int, warning: int, info: int}
     */
    private function summarize(array $findings): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'info' => 0];

        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return [
            'total' => count($findings),
            'critical' => $counts['critical'],
            'warning' => $counts['warning'],
            'info' => $counts['info'],
        ];
    }

    /**
     * @return array{rule_id: string, severity: string, file: string, message: string, reason_code: ?string}
     */
    private function toArray(Finding $finding): array
    {
        return [
            'rule_id' => $finding->ruleId,
            'severity' => $finding->severity->value,
            'file' => $finding->file->path,
            'message' => $finding->message,
            'reason_code' => $finding->reasonCode,
        ];
    }
}
