<?php

namespace TicoScope\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A real, throwaway Git repository created under the system temp directory
 * for exercising GitDiffReader against actual `git` output rather than
 * hand-rolled fixtures. Never created inside this package's own repository,
 * so a leftover from an interrupted test run can never pollute anything
 * tracked by git here.
 */
final class TemporaryGitRepository
{
    private readonly string $path;

    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'ticoscope-git-');

        if ($this->path === false) {
            throw new RuntimeException('Could not create a temporary directory for a test Git repository.');
        }

        unlink($this->path);
        mkdir($this->path);

        $this->git(['init', '--quiet']);
        $this->git(['config', 'user.email', 'ticoscope-tests@example.com']);
        $this->git(['config', 'user.name', 'TicoScope Tests']);
        $this->git(['config', 'commit.gpgsign', 'false']);
        $this->git(['checkout', '--quiet', '-b', 'main']);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function writeFile(string $relativePath, string $contents = ''): void
    {
        $fullPath = $this->path.'/'.$relativePath;

        if (! is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), recursive: true);
        }

        file_put_contents($fullPath, $contents);
    }

    public function deleteFile(string $relativePath): void
    {
        unlink($this->path.'/'.$relativePath);
    }

    public function renameFile(string $from, string $to): void
    {
        $destinationDirectory = dirname($this->path.'/'.$to);

        if (! is_dir($destinationDirectory)) {
            mkdir($destinationDirectory, recursive: true);
        }

        $this->git(['mv', $from, $to]);
    }

    public function commit(string $message = 'commit'): void
    {
        $this->git(['add', '-A']);
        $this->git(['commit', '--quiet', '-m', $message]);
    }

    public function checkoutNewBranch(string $name): void
    {
        $this->git(['checkout', '--quiet', '-b', $name]);
    }

    public function checkout(string $name): void
    {
        $this->git(['checkout', '--quiet', $name]);
    }

    private function git(array $arguments): void
    {
        $process = new Process(['git', ...$arguments], $this->path);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Test setup command failed: %s%s',
                $process->getCommandLine(),
                $process->getErrorOutput() !== '' ? "\n{$process->getErrorOutput()}" : '',
            ));
        }
    }

    public function __destruct()
    {
        $this->removeDirectory($this->path);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $directory.'/'.$item;

            if (is_dir($itemPath) && ! is_link($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($directory);
    }
}
