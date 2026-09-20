<?php

namespace TicoScope;

use Illuminate\Support\ServiceProvider;
use TicoScope\Console\TicoScopeCommand;
use TicoScope\Git\GitDiffReader;

final class TicoScopeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GitDiffReader::class, fn ($app) => new GitDiffReader($app->basePath()));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TicoScopeCommand::class,
            ]);
        }
    }
}
