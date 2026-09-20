<?php

namespace TicoScope\Git;

final class NotAGitRepositoryException extends GitException
{
    public static function forPath(string $path): self
    {
        return new self(sprintf('"%s" is not a Git repository.', $path));
    }
}
