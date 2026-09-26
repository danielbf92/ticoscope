<?php

namespace TicoScope\Php;

/**
 * Parses a .env-format document into the list of declared keys.
 * Deliberately small: recognizes simple `KEY=value` lines (optionally
 * `export`-prefixed), skipping blank lines and `#`-comments. Does not
 * attempt full dotenv semantics (multi-line quoted values, `${VAR}`
 * interpolation) — a stated, narrow limitation, not a silent gap, the
 * same posture every other small parser in this project takes toward its
 * own recognized-pattern-only scope.
 */
final class EnvExampleParser
{
    /**
     * @return string[]
     */
    public function parse(string $source): array
    {
        $keys = [];

        foreach (explode("\n", $source) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $trimmed, $matches) === 1) {
                $keys[] = $matches[1];
            }
        }

        return $keys;
    }
}
