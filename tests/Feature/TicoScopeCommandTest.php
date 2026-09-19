<?php

it('exits successfully and reports that no rules are registered yet', function () {
    $this->artisan('ticoscope:check')
        ->expectsOutputToContain('No analysis rules are registered yet')
        ->assertExitCode(0);
});

it('accepts a --base option', function () {
    $this->artisan('ticoscope:check', ['--base' => 'develop'])
        ->expectsOutputToContain('develop')
        ->assertExitCode(0);
});
