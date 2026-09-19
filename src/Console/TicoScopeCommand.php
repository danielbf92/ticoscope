<?php

namespace TicoScope\Console;

use Illuminate\Console\Command;

final class TicoScopeCommand extends Command
{
    protected $signature = 'ticoscope:check {--base=main : The revision to compare against}';

    protected $description = 'Analyze the changes since a base revision for deployment risk (not yet implemented)';

    public function handle(): int
    {
        $this->components->info(sprintf(
            'TicoScope is under construction (pre-v0.1). No analysis rules are registered yet. Requested base revision: "%s".',
            $this->option('base'),
        ));

        return self::SUCCESS;
    }
}
