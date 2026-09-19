<?php

namespace TicoScope\Rules;

use TicoScope\Diff\Diff;
use TicoScope\Findings\Finding;

interface Rule
{
    /**
     * A stable identifier for this rule (e.g. "migration.drop-column").
     *
     * Rule identifiers are part of the tool's public API: they appear in
     * console/JSON output and are what CI configuration and downstream
     * tooling reference, so they must not change once published.
     */
    public function id(): string;

    /**
     * @return Finding[]
     */
    public function analyze(Diff $diff): array;
}
