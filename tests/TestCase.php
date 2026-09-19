<?php

namespace TicoScope\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use TicoScope\TicoScopeServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TicoScopeServiceProvider::class,
        ];
    }
}
