<?php

namespace TicoScope\Php;

use Throwable;

/**
 * Extracts, from a migration's full source, direct table drops
 * (Schema::drop()/dropIfExists()) and Schema::table()/Schema::create()
 * closures' fluent call chains on their Blueprint variable.
 *
 * The one piece of this that needed real design work (confirmed by a
 * feasibility spike before this was written): a variable name shadowed by
 * a nested closure's own parameter must never be attributed to the outer
 * Blueprint. Every nested `function`/`fn` encountered while scanning a
 * closure's body is checked for exactly that before its contents are
 * treated as further calls on the tracked variable:
 *   - shadowed by its own parameter list -> never descended into, regardless
 *     of any `use` clause.
 *   - a regular `function (...) use (...) { ... }` -> only descended into
 *     if its own `use` clause explicitly lists the tracked variable name.
 *   - an arrow function `fn (...) => ...` -> auto-captures everything by
 *     value (no `use` clause exists for these), so descended into by
 *     default unless shadowed as above.
 *
 * Column name arguments that aren't literal (a variable, a call) are left
 * unresolved, never guessed — the same posture as every other extractor in
 * this project. `dropColumn(['a', 'b'])`'s array items are each resolved
 * independently; an unresolvable item is silently omitted rather than
 * voiding the whole call.
 */
final class SchemaCallExtractor
{
    public function extract(string $source): ?SchemaAnalysis
    {
        try {
            $tokens = token_get_all($source);
        } catch (Throwable) {
            return null;
        }

        return new SchemaAnalysis(
            $this->findDroppedTables($tokens),
            $this->findTableOperations($tokens),
        );
    }

    /**
     * @return string[]
     */
    private function findDroppedTables(array $tokens): array
    {
        $tables = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! $this->isSchemaReference($tokens[$i])) {
                continue;
            }

            $dc = $this->nextSignificant($tokens, $i + 1);

            if ($dc === null || $this->textOf($tokens[$dc]) !== '::') {
                continue;
            }

            $method = $this->nextSignificant($tokens, $dc + 1);

            if ($method === null || ! is_array($tokens[$method]) || $tokens[$method][0] !== T_STRING) {
                continue;
            }

            if (! in_array($tokens[$method][1], ['drop', 'dropIfExists'], true)) {
                continue;
            }

            $parenOpen = $this->nextSignificant($tokens, $method + 1);

            if ($parenOpen === null || $this->textOf($tokens[$parenOpen]) !== '(') {
                continue;
            }

            $parenClose = $this->matchDelimiter($tokens, $parenOpen);

            if ($parenClose === null) {
                continue;
            }

            $argIdx = $this->nextSignificant($tokens, $parenOpen + 1);

            if ($argIdx !== null && $argIdx < $parenClose && is_array($tokens[$argIdx]) && $tokens[$argIdx][0] === T_CONSTANT_ENCAPSED_STRING) {
                $tables[] = substr($tokens[$argIdx][1], 1, -1);
            }
        }

        return $tables;
    }

    /**
     * @return SchemaTableOperation[]
     */
    private function findTableOperations(array $tokens): array
    {
        $operations = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! $this->isSchemaReference($tokens[$i])) {
                continue;
            }

            $dc = $this->nextSignificant($tokens, $i + 1);

            if ($dc === null || $this->textOf($tokens[$dc]) !== '::') {
                continue;
            }

            $method = $this->nextSignificant($tokens, $dc + 1);

            if ($method === null || ! is_array($tokens[$method]) || $tokens[$method][0] !== T_STRING) {
                continue;
            }

            if (! in_array($tokens[$method][1], ['table', 'create'], true)) {
                continue;
            }

            $parenOpen = $this->nextSignificant($tokens, $method + 1);

            if ($parenOpen === null || $this->textOf($tokens[$parenOpen]) !== '(') {
                continue;
            }

            $parenClose = $this->matchDelimiter($tokens, $parenOpen);

            if ($parenClose === null) {
                continue;
            }

            $tableNameIdx = $this->nextSignificant($tokens, $parenOpen + 1);

            if ($tableNameIdx === null || ! is_array($tokens[$tableNameIdx]) || $tokens[$tableNameIdx][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $tableName = substr($tokens[$tableNameIdx][1], 1, -1);

            $closure = null;

            for ($j = $tableNameIdx + 1; $j < $parenClose; $j++) {
                if (is_array($tokens[$j]) && ($tokens[$j][0] === T_FUNCTION || $tokens[$j][0] === T_FN)) {
                    $closure = $this->parseClosureAt($tokens, $j, $parenClose);

                    if ($closure !== null) {
                        break;
                    }
                }
            }

            if ($closure === null) {
                continue;
            }

            $blueprintVar = $this->firstParameterVariable($tokens, $closure['paramOpen'], $closure['paramClose']);

            if ($blueprintVar === null) {
                continue;
            }

            $bodyEnd = $closure['isArrow'] ? $parenClose : $closure['bodyEnd'];
            $statements = $this->scanForCalls($tokens, $closure['bodyStart'], $bodyEnd, $blueprintVar);

            $operations[] = new SchemaTableOperation($tableName, $statements, $tokens[$method][1] === 'create');
        }

        return $operations;
    }

    /**
     * @return list<list<array{method: string, args: list<string|null|list<string>>}>>
     */
    private function scanForCalls(array $tokens, int $start, int $end, string $trackedVar): array
    {
        $statements = [];
        $i = $start;

        while ($i < $end) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === $trackedVar) {
                $arrow = $this->nextSignificant($tokens, $i + 1);

                if ($arrow !== null && $arrow < $end && $this->textOf($tokens[$arrow]) === '->') {
                    [$chain, $nextIndex] = $this->parseChain($tokens, $arrow, $end);

                    if ($chain !== []) {
                        $statements[] = $chain;
                    }

                    $i = $nextIndex;

                    continue;
                }

                $i++;

                continue;
            }

            if (is_array($token) && ($token[0] === T_FUNCTION || $token[0] === T_FN)) {
                $closure = $this->parseClosureAt($tokens, $i, $end);

                if ($closure === null) {
                    $i++;

                    continue;
                }

                $shadowed = $this->paramListContainsVariable($tokens, $closure['paramOpen'], $closure['paramClose'], $trackedVar);
                $captured = $closure['isArrow'] || in_array($trackedVar, $closure['useVars'], true);

                if ($shadowed || ! $captured) {
                    $i = $closure['isArrow']
                        ? $this->findArrowExpressionEnd($tokens, $closure['bodyStart'], $end)
                        : $closure['skipTo'];

                    continue;
                }

                // Not shadowed, and in scope (captured via `use`, or an
                // arrow function's automatic capture): let this same flat
                // scan continue naturally into the closure's body/expression
                // — no recursion needed, it'll find $trackedVar-> calls
                // there exactly as it would anywhere else.
                $i = $closure['bodyStart'];

                continue;
            }

            $i++;
        }

        return $statements;
    }

    /**
     * @return array{0: list<array{method: string, args: list<string|null|list<string>>}>, 1: int}
     */
    private function parseChain(array $tokens, int $firstArrow, int $hardEnd): array
    {
        $chain = [];
        $cursor = $firstArrow;

        while (true) {
            $methodIdx = $this->nextSignificant($tokens, $cursor + 1);

            if ($methodIdx === null || $methodIdx >= $hardEnd || ! is_array($tokens[$methodIdx]) || $tokens[$methodIdx][0] !== T_STRING) {
                break;
            }

            $parenOpen = $this->nextSignificant($tokens, $methodIdx + 1);

            if ($parenOpen === null || $parenOpen >= $hardEnd || $this->textOf($tokens[$parenOpen]) !== '(') {
                break;
            }

            $parenClose = $this->matchDelimiter($tokens, $parenOpen);

            if ($parenClose === null) {
                break;
            }

            $args = [];

            foreach ($this->splitTopLevel($tokens, $parenOpen + 1, $parenClose) as $argTokens) {
                $args[] = $this->resolveArgument($argTokens);
            }

            $chain[] = ['method' => $tokens[$methodIdx][1], 'args' => $args];

            $next = $this->nextSignificant($tokens, $parenClose + 1);

            if ($next !== null && $next < $hardEnd && $this->textOf($tokens[$next]) === '->') {
                $cursor = $next;

                continue;
            }

            return [$chain, $parenClose + 1];
        }

        return [$chain, $cursor + 1];
    }

    private function resolveArgument(array $argTokens): string|array|null
    {
        $significant = array_values(array_filter(
            $argTokens,
            fn ($t) => ! (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)),
        ));

        if (count($significant) === 1 && is_array($significant[0]) && $significant[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            return substr($significant[0][1], 1, -1);
        }

        if (count($significant) >= 2 && $this->textOf($significant[0]) === '[' && $this->textOf($significant[count($significant) - 1]) === ']') {
            $inner = array_slice($significant, 1, -1);
            $items = [];

            foreach ($this->splitTopLevel($inner, 0, count($inner)) as $itemTokens) {
                $resolved = $this->resolveArgument($itemTokens);

                if (is_string($resolved)) {
                    $items[] = $resolved;
                }
                // Non-literal items are silently omitted, not evaluated —
                // partial resolution rather than voiding the whole array.
            }

            return $items;
        }

        return null;
    }

    /**
     * Parses a closure/arrow-function header starting exactly at a
     * T_FUNCTION/T_FN token (an optional preceding `static` is irrelevant
     * here — nothing before the keyword itself needs inspecting).
     *
     * @return array{isArrow: bool, paramOpen: int, paramClose: int, useVars: string[], bodyStart: int, bodyEnd: ?int, skipTo: int}|null
     */
    private function parseClosureAt(array $tokens, int $functionIndex, int $hardEnd): ?array
    {
        $isArrow = is_array($tokens[$functionIndex]) && $tokens[$functionIndex][0] === T_FN;

        $paramOpen = $this->nextSignificant($tokens, $functionIndex + 1);

        if ($paramOpen === null || $paramOpen >= $hardEnd || $this->textOf($tokens[$paramOpen]) !== '(') {
            return null;
        }

        $paramClose = $this->matchDelimiter($tokens, $paramOpen);

        if ($paramClose === null) {
            return null;
        }

        if ($isArrow) {
            $arrow = $this->nextSignificant($tokens, $paramClose + 1);

            if ($arrow === null || $this->textOf($tokens[$arrow]) !== '=>') {
                return null;
            }

            return [
                'isArrow' => true,
                'paramOpen' => $paramOpen,
                'paramClose' => $paramClose,
                'useVars' => [],
                'bodyStart' => $arrow + 1,
                'bodyEnd' => null,
                'skipTo' => $hardEnd,
            ];
        }

        $afterParams = $this->nextSignificant($tokens, $paramClose + 1);
        $useVars = [];

        if ($afterParams !== null && is_array($tokens[$afterParams]) && $tokens[$afterParams][0] === T_USE) {
            $useOpen = $this->nextSignificant($tokens, $afterParams + 1);

            if ($useOpen === null || $this->textOf($tokens[$useOpen]) !== '(') {
                return null;
            }

            $useClose = $this->matchDelimiter($tokens, $useOpen);

            if ($useClose === null) {
                return null;
            }

            for ($k = $useOpen + 1; $k < $useClose; $k++) {
                if (is_array($tokens[$k]) && $tokens[$k][0] === T_VARIABLE) {
                    $useVars[] = $tokens[$k][1];
                }
            }

            $searchFrom = $useClose + 1;
        } else {
            $searchFrom = $paramClose + 1;
        }

        $bodyOpen = $this->nextSignificant($tokens, $searchFrom);

        if ($bodyOpen !== null && $this->textOf($tokens[$bodyOpen]) === ':') {
            // Skip an optional return-type declaration before the body.
            $bodyOpen = $this->findNextChar($tokens, $bodyOpen + 1, '{');
        }

        if ($bodyOpen === null || $this->textOf($tokens[$bodyOpen]) !== '{') {
            return null;
        }

        $bodyClose = $this->matchDelimiter($tokens, $bodyOpen);

        if ($bodyClose === null) {
            return null;
        }

        return [
            'isArrow' => false,
            'paramOpen' => $paramOpen,
            'paramClose' => $paramClose,
            'useVars' => $useVars,
            'bodyStart' => $bodyOpen + 1,
            'bodyEnd' => $bodyClose,
            'skipTo' => $bodyClose + 1,
        ];
    }

    private function firstParameterVariable(array $tokens, int $paramOpen, int $paramClose): ?string
    {
        for ($i = $paramOpen + 1; $i < $paramClose; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE) {
                return $tokens[$i][1];
            }
        }

        return null;
    }

    private function paramListContainsVariable(array $tokens, int $paramOpen, int $paramClose, string $varName): bool
    {
        for ($i = $paramOpen + 1; $i < $paramClose; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_VARIABLE && $tokens[$i][1] === $varName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Heuristic bound for a shadowed arrow function's expression body,
     * which (unlike a regular closure) has no explicit `{`/`}` delimiters:
     * extends until a depth-0 ',' or ';' is found, or a closing bracket
     * that belongs to an enclosing structure (depth would go negative) is
     * reached. Sufficient for the realistic case (a simple statement or a
     * callback argument); not an attempt at full expression-boundary
     * parsing, which this narrow case doesn't justify.
     */
    private function findArrowExpressionEnd(array $tokens, int $start, int $hardEnd): int
    {
        $depth = 0;

        for ($i = $start; $i < $hardEnd; $i++) {
            $text = $this->textOf($tokens[$i]);

            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;

                continue;
            }

            if ($text === ')' || $text === ']' || $text === '}') {
                if ($depth === 0) {
                    return $i;
                }

                $depth--;

                continue;
            }

            if ($depth === 0 && ($text === ',' || $text === ';')) {
                return $i + 1;
            }
        }

        return $hardEnd;
    }

    private function isSchemaReference(mixed $token): bool
    {
        if (! is_array($token)) {
            return false;
        }

        if ($token[0] === T_STRING && $token[1] === 'Schema') {
            return true;
        }

        return $token[0] === T_NAME_FULLY_QUALIFIED && str_ends_with($token[1], '\Schema');
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function splitTopLevel(array $tokens, int $start, int $end): array
    {
        $groups = [];
        $current = [];
        $depth = 0;

        for ($i = $start; $i < $end; $i++) {
            $token = $tokens[$i];
            $text = $this->textOf($token);

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
        $open = $this->textOf($tokens[$openIndex]);
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
            $text = $this->textOf($tokens[$i]);

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
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private function textOf(mixed $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }
}
