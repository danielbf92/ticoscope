<?php

namespace TicoScope\Diff;

use Countable;

final readonly class Diff implements Countable
{
    /**
     * @param ChangedFile[] $changedFiles
     */
    public function __construct(
        public string $baseRevision,
        public string $headRevision,
        public array $changedFiles = [],
    ) {
    }

    public function count(): int
    {
        return count($this->changedFiles);
    }
}
