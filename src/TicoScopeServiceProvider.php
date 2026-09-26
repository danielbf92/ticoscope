<?php

namespace TicoScope;

use Illuminate\Support\ServiceProvider;
use TicoScope\Analysis\Analyzer;
use TicoScope\Console\TicoScopeCommand;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Config\EnvWithoutDefaultRule;
use TicoScope\Rules\Migration\ColumnDroppedRule;
use TicoScope\Rules\Migration\TableDroppedRule;
use TicoScope\Rules\Queue\JobClassRemovedRule;
use TicoScope\Rules\Queue\JobConnectionChangedRule;
use TicoScope\Rules\Queue\JobFqcnChangedRule;
use TicoScope\Rules\Queue\JobPropertyRemovedRule;
use TicoScope\Rules\Queue\JobPropertyRetypedRule;
use TicoScope\Rules\Queue\JobQueueChangedRule;

final class TicoScopeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GitDiffReader::class, fn ($app) => new GitDiffReader($app->basePath()));

        $this->app->singleton(Analyzer::class, fn ($app) => new Analyzer([
            $app->make(EnvWithoutDefaultRule::class),
            $app->make(JobFqcnChangedRule::class),
            $app->make(JobClassRemovedRule::class),
            $app->make(JobPropertyRemovedRule::class),
            $app->make(JobPropertyRetypedRule::class),
            $app->make(JobConnectionChangedRule::class),
            $app->make(JobQueueChangedRule::class),
            $app->make(ColumnDroppedRule::class),
            $app->make(TableDroppedRule::class),
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
