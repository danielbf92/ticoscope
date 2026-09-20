<?php

namespace TicoScope\Classification;

use TicoScope\Diff\ChangedFile;

/**
 * Classifies a ChangedFile into a Laravel-relevant category by its
 * (destination) path. This is a necessary-condition heuristic, not proof —
 * see the QueueJob case below in particular.
 */
final class FileClassifier
{
    public function classify(ChangedFile $file): FileCategory
    {
        $path = $file->path;

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
