<?php

namespace TicoScope\Findings;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * Whether this severity is at or above the given threshold — the one
     * severity-ordering concept in the public API. Display ordering (e.g.
     * grouping findings CRITICAL → WARNING → INFO) is a reporter concern and
     * does not need this or any other public ordinal method.
     */
    public function meets(self $threshold): bool
    {
        $order = [self::Info, self::Warning, self::Critical];

        return array_search($this, $order, true) >= array_search($threshold, $order, true);
    }
}
