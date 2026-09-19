<?php

namespace TicoScope\Analysis;

use TicoScope\Diff\Diff;
use TicoScope\Findings\Finding;
use TicoScope\Rules\Rule;

/**
 * Runs every registered Rule against a Diff and collects the resulting
 * Findings. The Analyzer orchestrates; it does not itself judge what is
 * risky, and it has no knowledge of how (or whether) findings get reported.
 */
final class Analyzer
{
    /**
     * @param Rule[] $rules
     */
    public function __construct(
        private readonly array $rules = [],
    ) {
    }

    /**
     * @return Finding[]
     */
    public function analyze(Diff $diff): array
    {
        $findings = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->analyze($diff) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}
