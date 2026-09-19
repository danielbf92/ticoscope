<?php

namespace TicoScope;

use Illuminate\Support\ServiceProvider;
use TicoScope\Console\TicoScopeCommand;

final class TicoScopeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TicoScopeCommand::class,
            ]);
        }
    }
}
