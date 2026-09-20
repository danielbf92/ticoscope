<?php

namespace TicoScope\Rules\Config;

use Throwable;
use TicoScope\Classification\FileCategory;
use TicoScope\Classification\FileClassifier;
use TicoScope\Diff\AddedLineExtractor;
use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\Diff;
use TicoScope\Findings\Finding;
use TicoScope\Findings\Severity;
use TicoScope\Rules\Rule;

/**
 * Flags a newly-added env() call in a config file that has no usable
 * fallback — the config:cache hazard VISION.md calls out as the strongest
 * single feature to build first.
 *
 * Only lines actually added by this change are scanned (via
 * AddedLineExtractor), not the whole current file: an existing, untouched
 * env() call elsewhere in the file must never re-fire just because an
 * unrelated line changed.
 */
final class EnvWithoutDefaultRule implements Rule
{
    public function __construct(
        private readonly FileClassifier $classifier = new FileClassifier(),
        private readonly AddedLineExtractor $addedLineExtractor = new AddedLineExtractor(),
    ) {
    }

    public function id(): string
    {
        return 'config.env-without-default';
    }

    /**
     * @return Finding[]
     */
    public function analyze(Diff $diff): array
    {
        $findings = [];

        foreach ($diff->changedFiles as $file) {
            if ($this->classifier->classify($file) !== FileCategory::Config) {
                continue;
            }

            foreach ($this->addedLineExtractor->extract($file) as $addedRun) {
                foreach ($this->findOffendingCalls($addedRun) as $call) {
                    $findings[] = $this->toFinding($file, $call['variable'], $call['nullFallback']);
                }
            }
        }

        return $findings;
    }

    private function toFinding(ChangedFile $file, string $variable, bool $nullFallback): Finding
    {
        $message = $nullFallback
            ? sprintf(
                'New env() call for %s explicitly falls back to null, which is not a usable default. '.
                'If the variable is missing when configuration is cached, this config value may resolve to null.',
                $variable,
            )
            : sprintf(
                'New env() call for %s has no fallback. '.
                'If the variable is missing when configuration is cached, this config value may resolve to null.',
                $variable,
            );

        return new Finding(
            ruleId: $this->id(),
            severity: Severity::Warning,
            file: $file,
            message: $message,
            reasonCode: $variable,
        );
    }

    /**
     * Scans one contiguous run of newly-added source for env() calls with no
     * usable fallback. Tokenized without TOKEN_PARSE so a malformed or
     * incomplete fragment (an added run is a fragment of a file, not a full
     * valid program) never throws; the try/catch is a second line of defense
     * so an unexpected failure degrades to "no finding," not a crashed
     * analysis run.
     *
     * @return list<array{variable: string, nullFallback: bool}>
     */
    private function findOffendingCalls(string $addedSource): array
    {
        try {
            $tokens = token_get_all("<?php\n".$addedSource);
        } catch (Throwable) {
            return [];
        }

        $results = [];
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

            $call = $this->parseCallArguments($tokens, $openParen + 1);

            if ($call === null) {
                // Matching ')' never found within this added run — the call
                // spans into unchanged/context lines we don't have. Fail
                // conservatively: no finding, rather than guessing.
                continue;
            }

            $arguments = $call;

            if (count($arguments) === 0) {
                continue;
            }

            $variable = $this->extractStringLiteral($arguments[0]);

            if ($variable === null) {
                continue;
            }

            if (count($arguments) === 1) {
                $results[] = ['variable' => $variable, 'nullFallback' => false];

                continue;
            }

            if ($this->isBareNullLiteral($arguments[1])) {
                $results[] = ['variable' => $variable, 'nullFallback' => true];
            }
        }

        return $results;
    }

    /**
     * Walks tokens starting right after a call's opening '(', tracking
     * nested paren/bracket/brace depth, splitting depth-0 content on commas.
     *
     * @return list<array<int, mixed>>|null the call's arguments (each an
     *     array of tokens), or null if the matching ')' is never reached.
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
        $significant = $this->filterSignificant($argumentTokens);

        if (count($significant) !== 1) {
            return null;
        }

        $token = $significant[0];

        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return substr($token[1], 1, -1);
    }

    private function isBareNullLiteral(array $argumentTokens): bool
    {
        $significant = $this->filterSignificant($argumentTokens);

        if (count($significant) !== 1) {
            return false;
        }

        $token = $significant[0];

        return is_array($token) && strtolower($token[1]) === 'null';
    }

    /**
     * @return array<int, mixed>
     */
    private function filterSignificant(array $tokens): array
    {
        return array_values(array_filter($tokens, fn ($token) => ! $this->isInsignificant($token)));
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
