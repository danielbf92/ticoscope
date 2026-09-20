<?php

use TicoScope\Classification\FileCategory;
use TicoScope\Classification\FileClassifier;
use TicoScope\Diff\ChangedFile;
use TicoScope\Diff\ChangeType;

function classify(string $path): FileCategory
{
    return (new FileClassifier())->classify(new ChangedFile(path: $path, changeType: ChangeType::Modified));
}

it('classifies a flat migration file', function () {
    expect(classify('database/migrations/2026_01_01_000000_create_orders_table.php'))
        ->toBe(FileCategory::Migration);
});

it('classifies a flat config file', function () {
    expect(classify('config/services.php'))->toBe(FileCategory::Config);
});

it('classifies a flat route file', function () {
    expect(classify('routes/web.php'))->toBe(FileCategory::Route);
});

it('classifies .env.example exactly', function () {
    expect(classify('.env.example'))->toBe(FileCategory::EnvExample);
});

it('classifies composer.json and composer.lock exactly', function () {
    expect(classify('composer.json'))->toBe(FileCategory::Composer);
    expect(classify('composer.lock'))->toBe(FileCategory::Composer);
});

it('classifies a flat Job file as QueueJob', function () {
    expect(classify('app/Jobs/SendEmail.php'))->toBe(FileCategory::QueueJob);
});

it('classifies nested Job directories as QueueJob, recursively', function () {
    // Known, deliberate limitation: this is a path heuristic, not proof the
    // class implements ShouldQueue. A future queue-compatibility milestone
    // is responsible for content-based confirmation.
    expect(classify('app/Jobs/Billing/SyncInvoice.php'))->toBe(FileCategory::QueueJob);
    expect(classify('app/Jobs/Reports/Daily/GenerateReport.php'))->toBe(FileCategory::QueueJob);
});

it('does not classify nested config subdirectories as Config', function () {
    expect(classify('config/foo/bar.php'))->toBe(FileCategory::Unclassified);
});

it('does not classify nested route subdirectories as Route', function () {
    expect(classify('routes/api/v1.php'))->toBe(FileCategory::Unclassified);
});

it('classifies anything else as Unclassified', function () {
    expect(classify('app/Http/Controllers/HomeController.php'))->toBe(FileCategory::Unclassified);
    expect(classify('README.md'))->toBe(FileCategory::Unclassified);
});

it('exposes classifyPath() directly for classifying a bare path', function () {
    $classifier = new FileClassifier();

    expect($classifier->classifyPath('app/Jobs/Billing/SyncInvoice.php'))->toBe(FileCategory::QueueJob);
    expect($classifier->classifyPath('config/services.php'))->toBe(FileCategory::Config);
    expect($classifier->classifyPath('app/Services/GenerateReport.php'))->toBe(FileCategory::Unclassified);
});

it('classifies a renamed ChangedFile by its destination path, not its original path', function () {
    $classifier = new FileClassifier();

    $movedOutOfJobs = new ChangedFile(
        path: 'app/Legacy/GenerateReport.php',
        changeType: ChangeType::Renamed,
        originalPath: 'app/Jobs/GenerateReport.php',
    );

    // classify() reflects "what is this file now" (Milestone 3, unchanged).
    // A rule that needs "what was this file before" must call
    // classifyPath($file->originalPath) directly instead.
    expect($classifier->classify($movedOutOfJobs))->toBe(FileCategory::Unclassified);
    expect($classifier->classifyPath($movedOutOfJobs->originalPath))->toBe(FileCategory::QueueJob);
});
