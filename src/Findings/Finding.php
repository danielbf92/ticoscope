<?php

namespace TicoScope\Findings;

use TicoScope\Diff\ChangedFile;

final readonly class Finding
{
    public function __construct(
        public string $ruleId,
        public Severity $severity,
        public ChangedFile $file,
        public string $message,
        public ?string $reasonCode = null,
    ) {
    }
}
