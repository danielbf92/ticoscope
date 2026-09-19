<?php

namespace TicoScope\Reporting;

use TicoScope\Findings\Finding;

interface Reporter
{
    /**
     * Render a collection of findings to a string. Reporters do not perform
     * I/O themselves; the caller decides what to do with the result.
     *
     * @param Finding[] $findings
     */
    public function report(array $findings): string;
}
