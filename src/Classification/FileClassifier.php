<?php

namespace TicoScope\Classification;

use TicoScope\Diff\ChangedFile;

/**
 * Classifies a path into a Laravel-relevant category. This is a
 * necessary-condition heuristic, not proof — see the QueueJob case below in
 * particular.
 */
final class FileClassifier
{
    /**
     * Classifies a ChangedFile by its destination (current) path. For a
     * rename, this reflects what the file *is now* — established in
     * Milestone 3 and unchanged here. Rules that need to know what a file
     * *was* before the change (e.g. a temporal "was this a Job candidate
     * before this deployment" question) should call classifyPath()
     * directly with the pre-change path instead.
     */
    public function classify(ChangedFile $file): FileCategory
    {
        return $this->classifyPath($file->path);
    }

    public function classifyPath(string $path): FileCategory
    {
        if ($path === '.env.example') {
            return FileCategory::EnvExample;
        }

        if ($path === 'composer.json' || $path === 'composer.lock') {
            return FileCategory::Composer;
        }

        if ($this->isFlatPhpFileIn($path, 'database/migrations')) {
            return FileCategory::Migration;
        }

        if ($this->isFlatPhpFileIn($path, 'config')) {
            return FileCategory::Config;
        }

        if ($this->isFlatPhpFileIn($path, 'routes')) {
            return FileCategory::Route;
        }

        // Recursive, unlike the categories above: a queued Job commonly lives
        // in a nested subdirectory (app/Jobs/Billing/SyncInvoice.php). This
        // only tells us the path *looks like* a Job — it does not confirm the
        // class implements ShouldQueue. Content-based confirmation is a
        // future queue-compatibility milestone's job, not this classifier's.
        if (str_starts_with($path, 'app/Jobs/') && str_ends_with($path, '.php')) {
            return FileCategory::QueueJob;
        }

        return FileCategory::Unclassified;
    }

    private function isFlatPhpFileIn(string $path, string $directory): bool
    {
        return dirname($path) === $directory && str_ends_with($path, '.php');
    }
}
