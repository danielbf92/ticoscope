<?php

namespace TicoScope\Php;

use Throwable;

/**
 * Extracts a file's declared fully-qualified class name (namespace + first
 * class identifier) from its complete source. Deliberately narrow: this is
 * not a general PHP parser, just enough structure to answer "what FQCN does
 * this file declare," which is what queued-Job identity-compatibility rules
 * need.
 *
 * Only the semicolon form of the namespace statement is supported
 * (`namespace Foo\Bar;`), and only the first class declaration in the file
 * is considered — both match universal Laravel/PSR-12 convention (one class
 * per file). This is a stated, deliberate simplification, not a silent gap.
 */
final class FqcnExtractor
{
    public function extract(string $source): ?string
    {
        try {
            $tokens = token_get_all($source);
        } catch (Throwable) {
            return null;
        }

        $namespaceName = '';
        $namespaceUnsupported = false;
        $namespaceFound = false;
        $className = null;

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if (! $namespaceFound && $token[0] === T_NAMESPACE) {
                $namespaceFound = true;
                $next = $this->nextSignificant($tokens, $i + 1);

                if ($next === null) {
                    $namespaceUnsupported = true;
                } elseif ($tokens[$next] === ';') {
                    $namespaceName = '';
                } elseif (is_array($tokens[$next]) && in_array($tokens[$next][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    // Only the semicolon form is supported — the name must
                    // be immediately terminated by ';'. Brace-block syntax
                    // ("namespace Foo\Bar { ... }") has the same name token
                    // here but is terminated by '{' instead; fail
                    // conservatively rather than guessing at its extent.
                    $terminator = $this->nextSignificant($tokens, $next + 1);

                    if ($terminator !== null && $tokens[$terminator] === ';') {
                        $namespaceName = $tokens[$next][1];
                    } else {
                        $namespaceUnsupported = true;
                    }
                } else {
                    $namespaceUnsupported = true;
                }

                continue;
            }

            if ($className === null && $token[0] === T_CLASS) {
                // A genuine declaration ("class Foo") is a T_CLASS token
                // immediately followed by a T_STRING. Foo::class is followed
                // by whatever comes after the fetch (';', ',', ')', ...),
                // and `new class { ... }` is followed by
                // extends/implements/'{' — neither is ever a bare T_STRING,
                // so no lookbehind is needed to exclude them.
                $next = $this->nextSignificant($tokens, $i + 1);

                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    $className = $tokens[$next][1];
                }
            }
        }

        if ($namespaceUnsupported || $className === null) {
            return null;
        }

        return $namespaceName === '' ? $className : "{$namespaceName}\\{$className}";
    }

    private function nextSignificant(array $tokens, int $start): ?int
    {
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
