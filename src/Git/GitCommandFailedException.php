<?php

namespace TicoScope\Git;

final class GitCommandFailedException extends GitException
{
    public static function fromProcessFailure(string $commandLine, string $errorOutput): self
    {
        return new self(sprintf(
            'Git command failed: %s%s',
            $commandLine,
            $errorOutput !== '' ? "\n{$errorOutput}" : '',
        ));
    }

    public static function malformedOutput(string $reason): self
    {
        return new self(sprintf('Git produced unexpected output: %s', $reason));
    }
}
