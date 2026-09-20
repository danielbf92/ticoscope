<?php

namespace TicoScope\Diff;

/**
 * Extracts the newly-added source from a ChangedFile's unified diff patch.
 *
 * Deliberately small: this is not a general diff-parsing framework, just
 * "give me the added source, grouped the way a multi-line statement needs it
 * to stay together" — every content-inspecting Rule needs this same
 * primitive, so it lives here rather than inside any one Rule.
 */
final class AddedLineExtractor
{
    /**
     * Returns each maximal contiguous run of added ('+') lines, stripped of
     * their leading '+' and joined with "\n", in the order they appear in
     * the patch. The '+++ b/...' file header is never treated as source.
     *
     * @return string[]
     */
    public function extract(ChangedFile $file): array
    {
        $patch = $file->patch;

        if ($patch === null || trim($patch) === '') {
            return [];
        }

        $runs = [];
        $current = [];

        foreach (explode("\n", $patch) as $line) {
            if (str_starts_with($line, '+++ ')) {
                if ($current !== []) {
                    $runs[] = implode("\n", $current);
                    $current = [];
                }

                continue;
            }

            if ($line !== '' && $line[0] === '+') {
                $current[] = substr($line, 1);

                continue;
            }

            if ($current !== []) {
                $runs[] = implode("\n", $current);
                $current = [];
            }
        }

        if ($current !== []) {
            $runs[] = implode("\n", $current);
        }

        return $runs;
    }
}
