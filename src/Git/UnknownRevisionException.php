<?php

namespace TicoScope\Git;

final class UnknownRevisionException extends GitException
{
    public static function forRevision(string $revision): self
    {
        return new self(sprintf('"%s" is not a revision known to this Git repository.', $revision));
    }
}
