<?php

namespace TicoScope\Php;

use Throwable;

/**
 * Extracts every variable name referenced via env('X', ...) in a fragment
 * of source (typically a newly-added run from AddedLineExtractor) —
 * regardless of whether a fallback argument is present. This is a
 * deliberately different filter from EnvWithoutDefaultRule's own private
 * fallback-parsing logic (src/Rules/Config/EnvWithoutDefaultRule.php),
 * which only returns calls with NO usable fallback; this extractor needs
 * to know about every reference, fallback or not, to cross-check it
 * against .env.example.
 *
 * Tokenized without TOKEN_PARSE so a malformed or incomplete fragment (an
 * added run is a fragment of a file, not a full valid program) never
 * throws.
 */
final class EnvCallExtractor
{
    /**
     * @return string[]
     */
    public function extract(string $source): array
    {
        try {
            $tokens = token_get_all("<?php\n".$source);
        } catch (Throwable) {
            return [];
        }

        $variables = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'env') {
                continue;
            }

            $openParen = $this->nextSignificant($tokens, $i + 1);

            if ($openParen === null || $tokens[$openParen] !== '(') {
                continue;
            }

            $arguments = $this->parseCallArguments($tokens, $openParen + 1);

            if ($arguments === null || count($arguments) === 0) {
                continue;
            }

            $variable = $this->extractStringLiteral($arguments[0]);

            if ($variable !== null) {
                $variables[] = $variable;
            }
        }

        return $variables;
    }

    /**
     * @return list<array<int, mixed>>|null
     */
    private function parseCallArguments(array $tokens, int $start): ?array
    {
        $depth = 0;
        $arguments = [];
        $current = [];
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                $current[] = $token;

                continue;
            }

            if ($text === ')' || $text === ']' || $text === '}') {
                if ($text === ')' && $depth === 0) {
                    if ($current !== []) {
                        $arguments[] = $current;
                    }

                    return $arguments;
                }

                $depth--;
                $current[] = $token;

                continue;
            }

            if ($text === ',' && $depth === 0) {
                $arguments[] = $current;
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        return null;
    }

    private function extractStringLiteral(array $argumentTokens): ?string
    {
        $significant = array_values(array_filter($argumentTokens, fn ($token) => ! $this->isInsignificant($token)));

        if (count($significant) !== 1) {
            return null;
        }

        $token = $significant[0];

        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return substr($token[1], 1, -1);
    }

    private function isInsignificant(mixed $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    private function nextSignificant(array $tokens, int $start): ?int
    {
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            if (! $this->isInsignificant($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }
}
