<?php

namespace TicoScope;

use Illuminate\Support\ServiceProvider;
use TicoScope\Analysis\Analyzer;
use TicoScope\Console\TicoScopeCommand;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Config\EnvWithoutDefaultRule;

final class TicoScopeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GitDiffReader::class, fn ($app) => new GitDiffReader($app->basePath()));

        $this->app->singleton(Analyzer::class, fn ($app) => new Analyzer([
            $app->make(EnvWithoutDefaultRule::class),
        ]));
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
