<?php

namespace TicoScope\Php;

/**
 * A single non-static public property declared on a class — either
 * traditionally or via constructor promotion, with no distinction tracked
 * between the two (see PublicPropertyExtractor).
 */
final readonly class PublicPropertyInfo
{
    public function __construct(
        public string $name,
        public ?string $type,
    ) {
    }
}
