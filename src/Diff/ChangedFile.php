<?php

namespace TicoScope\Diff;

final readonly class ChangedFile
{
    public function __construct(
        public string $path,
        public ChangeType $changeType,
        public ?string $patch = null,
        public ?string $originalPath = null,
    ) {
    }
}
