<?php

namespace TicoScope\Php;

use Throwable;

/**
 * Extracts a class's non-static public properties — declared traditionally
 * or via constructor promotion, with no distinction tracked between the two
 * — keyed by name. Scoped to exactly what queued-Job property-compatibility
 * rules need: does this property exist, and with what declared type.
 *
 * Static properties are deliberately excluded: serialize() never touches
 * them (they belong to the class, not the instance), so a queued job's
 * payload never contains one, and a static property changing has no bearing
 * on already-queued job compatibility.
 *
 * Only the first variable in a comma-separated multi-property declaration
 * (`public int $a, $b;`) is captured — rare and discouraged in modern PHP,
 * matching the same "first wins" posture FqcnExtractor takes for multiple
 * class declarations in one file.
 */
final class PublicPropertyExtractor
{
    private const MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY];

    /**
     * @return array<string, PublicPropertyInfo>|null
     */
    public function extract(string $source): ?array
    {
        try {
            $tokens = token_get_all($source);
        } catch (Throwable) {
            return null;
        }

        $bodyStart = $this->findClassBodyStart($tokens);

        if ($bodyStart === null) {
            return null;
        }

        return $this->scanClassBody($tokens, $bodyStart);
    }

    private function findClassBodyStart(array $tokens): ?int
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            // Same exclusion as FqcnExtractor: a genuine declaration is
            // T_CLASS immediately followed by T_STRING; Foo::class and
            // `new class {}` never are.
            $next = $this->nextSignificant($tokens, $i + 1);

            if ($next === null || ! is_array($tokens[$next]) || $tokens[$next][0] !== T_STRING) {
                continue;
            }

            $brace = $this->findNextChar($tokens, $next + 1, '{');

            return $brace === null ? null : $brace + 1;
        }

        return null;
    }

    /**
     * @return array<string, PublicPropertyInfo>
     */
    private function scanClassBody(array $tokens, int $start): array
    {
        $properties = [];
        $depth = 1;
        $count = count($tokens);
        $i = $start;

        while ($i < $count && $depth > 0) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '{' || $text === '(' || $text === '[') {
                $depth++;
                $i++;

                continue;
            }

            if ($text === '}' || $text === ')' || $text === ']') {
                $depth--;
                $i++;

                continue;
            }

            if ($depth !== 1 || ! is_array($token) || ! in_array($token[0], self::MODIFIERS, true)) {
                $i++;

                continue;
            }

            [$next, $modifiers] = $this->consumeModifiers($tokens, $i);

            if ($next === null) {
                break;
            }

            if (is_array($tokens[$next]) && $tokens[$next][0] === T_FUNCTION) {
                $nameIndex = $this->nextSignificant($tokens, $next + 1);
                $isConstructor = $nameIndex !== null
                    && is_array($tokens[$nameIndex])
                    && strtolower($tokens[$nameIndex][1]) === '__construct';

                $parenOpen = $nameIndex === null ? null : $this->findNextChar($tokens, $nameIndex + 1, '(');

                if ($parenOpen === null) {
                    break;
                }

                $parenClose = $this->matchDelimiter($tokens, $parenOpen);

                if ($parenClose === null) {
                    break;
                }

                if ($isConstructor) {
                    foreach ($this->extractPromotedProperties($tokens, $parenOpen, $parenClose) as $property) {
                        $properties[$property->name] = $property;
                    }
                }

                // Resume the same depth-tracking loop right after the
                // parameter list — it will naturally walk through any
                // return-type tokens and then the method body's own braces
                // (or a bare ';' for an abstract method), incrementing and
                // decrementing $depth exactly as it already does for the
                // class body itself. No separate "skip the method" routine
                // is needed.
                $i = $parenClose + 1;

                continue;
            }

            [$name, $type, $statementEnd] = $this->parsePropertyDeclaration($tokens, $next);

            if ($name === null) {
                break;
            }

            $isPublic = in_array(T_PUBLIC, $modifiers, true);
            $isStatic = in_array(T_STATIC, $modifiers, true);

            if ($isPublic && ! $isStatic) {
                $properties[$name] = new PublicPropertyInfo($name, $type);
            }

            $i = $statementEnd;
        }

        return $properties;
    }

    /**
     * @return array{0: ?int, 1: int[]}
     */
    private function consumeModifiers(array $tokens, int $start): array
    {
        $modifiers = [];
        $count = count($tokens);
        $i = $start;

        while ($i < $count && is_array($tokens[$i]) && in_array($tokens[$i][0], self::MODIFIERS, true)) {
            $modifiers[] = $tokens[$i][0];
            $next = $this->nextSignificant($tokens, $i + 1);

            if ($next === null) {
                return [null, $modifiers];
            }

            $i = $next;
        }

        return [$i, $modifiers];
    }

    /**
     * Reads a property declaration starting right after its modifiers:
     * an optional type expression, then a T_VARIABLE, then ';' (skipping
     * over any '= <default>' — its value is irrelevant to this milestone).
     *
     * @return array{0: ?string, 1: ?string, 2: int} [name, type, indexAfterStatement]
     */
    private function parsePropertyDeclaration(array $tokens, int $start): array
    {
        $count = count($tokens);
        $typeTokens = [];
        $i = $start;

        while ($i < $count && ! (is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE)) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
                // A modifier chain that isn't a property or a recognized
                // method shape (shouldn't normally happen here, since the
                // T_FUNCTION case is handled before this is called) —
                // fail conservatively rather than mis-scan further.
                return [null, null, $count];
            }

            if (! (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE)) {
                $typeTokens[] = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            }

            $i++;
        }

        if ($i >= $count) {
            return [null, null, $count];
        }

        $name = ltrim($tokens[$i][1], '$');
        $type = $typeTokens === [] ? null : implode('', $typeTokens);

        $semicolon = $this->findNextChar($tokens, $i + 1, ';');
        $end = $semicolon === null ? $count : $semicolon + 1;

        return [$name, $type, $end];
    }

    /**
     * @return PublicPropertyInfo[]
     */
    private function extractPromotedProperties(array $tokens, int $parenOpen, int $parenClose): array
    {
        $properties = [];

        foreach ($this->splitTopLevel($tokens, $parenOpen + 1, $parenClose) as $paramTokens) {
            $first = $this->nextSignificantIn($paramTokens, 0);

            if ($first === null || ! is_array($paramTokens[$first]) || ! in_array($paramTokens[$first][0], self::MODIFIERS, true)) {
                continue;
            }

            $modifiers = [];
            $i = $first;
            $count = count($paramTokens);

            while ($i < $count && is_array($paramTokens[$i]) && in_array($paramTokens[$i][0], self::MODIFIERS, true)) {
                $modifiers[] = $paramTokens[$i][0];
                $next = $this->nextSignificantIn($paramTokens, $i + 1);
                $i = $next ?? $count;
            }

            if (! in_array(T_PUBLIC, $modifiers, true) || in_array(T_STATIC, $modifiers, true)) {
                continue;
            }

            $typeTokens = [];

            while ($i < $count && ! (is_array($paramTokens[$i]) && $paramTokens[$i][0] === T_VARIABLE)) {
                if (! (is_array($paramTokens[$i]) && $paramTokens[$i][0] === T_WHITESPACE)) {
                    $typeTokens[] = is_array($paramTokens[$i]) ? $paramTokens[$i][1] : $paramTokens[$i];
                }

                $i++;
            }

            if ($i >= $count) {
                continue;
            }

            $name = ltrim($paramTokens[$i][1], '$');
            $type = $typeTokens === [] ? null : implode('', $typeTokens);

            $properties[] = new PublicPropertyInfo($name, $type);
        }

        return $properties;
    }

    /**
     * Splits tokens[start..end) on top-level commas (depth-aware over
     * '(', '[', '{' and their closers) — the same technique
     * EnvWithoutDefaultRule uses for call arguments, applied to a
     * parameter list instead.
     *
     * @return list<array<int, mixed>>
     */
    private function splitTopLevel(array $tokens, int $start, int $end): array
    {
        $groups = [];
        $current = [];
        $depth = 0;

        for ($i = $start; $i < $end; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                $current[] = $token;

                continue;
            }

            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                $current[] = $token;

                continue;
            }

            if ($text === ',' && $depth === 0) {
                $groups[] = $current;
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    private function matchDelimiter(array $tokens, int $openIndex): ?int
    {
        $open = is_array($tokens[$openIndex]) ? $tokens[$openIndex][1] : $tokens[$openIndex];
        $close = match ($open) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            default => null,
        };

        if ($close === null) {
            return null;
        }

        $depth = 0;
        $count = count($tokens);

        for ($i = $openIndex; $i < $count; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

            if ($text === $open) {
                $depth++;
            } elseif ($text === $close) {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function findNextChar(array $tokens, int $start, string $char): ?int
    {
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            if (! is_array($tokens[$i]) && $tokens[$i] === $char) {
                return $i;
            }
        }

        return null;
    }

    private function nextSignificant(array $tokens, int $start): ?int
    {
        return $this->nextSignificantIn($tokens, $start);
    }

    private function nextSignificantIn(array $tokens, int $start): ?int
    {
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
