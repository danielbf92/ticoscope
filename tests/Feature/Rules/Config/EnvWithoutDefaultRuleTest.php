<?php

use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Config\EnvWithoutDefaultRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

it('fires for a newly added env() call with no arguments beyond the variable name', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n];\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n    'endpoint' => env('REPORTING_ENDPOINT'),\n];\n");
    $repo->commit('add env call');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $findings = (new EnvWithoutDefaultRule())->analyze($diff);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('config.env-without-default');
    expect($findings[0]->reasonCode)->toBe('REPORTING_ENDPOINT');
    expect($findings[0]->message)->toContain('REPORTING_ENDPOINT');
    expect($findings[0]->message)->toContain('has no fallback');
});

it('fires for an explicit null fallback, case-insensitively', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n];\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('config/services.php', <<<'PHP'
        <?php

        return [
            'existing' => 'value',
            'a' => env('NULL_LOWER', null),
            'b' => env('NULL_UPPER', NULL),
            'c' => env('NULL_MIXED', Null),
        ];
        PHP);
    $repo->commit('add null-fallback env calls');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $findings = (new EnvWithoutDefaultRule())->analyze($diff);

    expect($findings)->toHaveCount(3);

    $byVariable = [];
    foreach ($findings as $finding) {
        $byVariable[$finding->reasonCode] = $finding;
    }

    foreach (['NULL_LOWER', 'NULL_UPPER', 'NULL_MIXED'] as $variable) {
        expect($byVariable)->toHaveKey($variable);
        expect($byVariable[$variable]->message)->toContain('explicitly falls back to null');
    }
});

it('does not fire when a usable fallback value is provided', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n];\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('config/services.php', <<<'PHP'
        <?php

        return [
            'existing' => 'value',
            'a' => env('WITH_STRING_DEFAULT', 'default'),
            'b' => env('WITH_EMPTY_STRING_DEFAULT', ''),
            'c' => env('WITH_FALSE_DEFAULT', false),
            'd' => env('WITH_ZERO_DEFAULT', 0),
            'e' => env('WITH_EXPRESSION_DEFAULT', config('fallback.value')),
        ];
        PHP);
    $repo->commit('add env calls with usable fallbacks');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $findings = (new EnvWithoutDefaultRule())->analyze($diff);

    expect($findings)->toBe([]);
});

it('does not re-fire for an existing env() call when an unrelated line changes', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'endpoint' => env('REPORTING_ENDPOINT'),\n    'unrelated' => 'old value',\n];\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'endpoint' => env('REPORTING_ENDPOINT'),\n    'unrelated' => 'new value',\n];\n");
    $repo->commit('touch an unrelated line');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $findings = (new EnvWithoutDefaultRule())->analyze($diff);

    expect($findings)->toBe([]);
});

it('does not fire for an env() call that was only removed', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'endpoint' => env('REPORTING_ENDPOINT'),\n];\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n];\n");
    $repo->commit('remove env call');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $findings = (new EnvWithoutDefaultRule())->analyze($diff);

    expect($findings)->toBe([]);
});

it('fires for a multi-line env() call fully contained in one added run', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n];\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('config/services.php', <<<'PHP'
        <?php

        return [
            'existing' => 'value',
            'endpoint' => env(
                'REPORTING_ENDPOINT'
            ),
        ];
        PHP);
    $repo->commit('add multi-line env call');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $findings = (new EnvWithoutDefaultRule())->analyze($diff);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->reasonCode)->toBe('REPORTING_ENDPOINT');
});

it('does not fire for an env()-looking string inside an added comment', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n];\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('config/services.php', "<?php\n\n// env('OLD_STYLE') used to live here\nreturn [\n    'existing' => 'value',\n];\n");
    $repo->commit('add comment mentioning env()');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $findings = (new EnvWithoutDefaultRule())->analyze($diff);

    expect($findings)->toBe([]);
});

it('does not fire for a non-Config-classified file with the same added line', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Support/Example.php', "<?php\n\nclass Example\n{\n}\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Support/Example.php', "<?php\n\nclass Example\n{\n    public string \$endpoint = '';\n\n    public function __construct()\n    {\n        \$this->endpoint = env('REPORTING_ENDPOINT');\n    }\n}\n");
    $repo->commit('add env call outside config/');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $findings = (new EnvWithoutDefaultRule())->analyze($diff);

    expect($findings)->toBe([]);
});

it('does not fire when the env() call is truncated by an unchanged closing line', function () {
    // Documented limitation: an added run can contain only part of a call
    // when its closing token sits on a line the diff shows as unchanged
    // context. This proves the rule fails conservatively (no finding)
    // rather than guessing or misreporting the variable name.
    $repo = new TemporaryGitRepository();
    $repo->writeFile('config/services.php', <<<'PHP'
        <?php

        return [
            'endpoint' => oldFunctionCall(
                'value'
            ),
        ];
        PHP);
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('config/services.php', <<<'PHP'
        <?php

        return [
            'endpoint' => env(
                'REPORTING_ENDPOINT'
            ),
        ];
        PHP);
    $repo->commit('swap in an env() call, keeping the closing line unchanged');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');

    // Confirms the fixture actually produces the intended shape before
    // trusting the rule's behavior against it.
    expect($diff->changedFiles[0]->patch)->toContain("+    'endpoint' => env(");
    expect($diff->changedFiles[0]->patch)->not->toContain("+    ),");

    $findings = (new EnvWithoutDefaultRule())->analyze($diff);

    expect($findings)->toBe([]);
});

it('completes without throwing when an added run contains malformed PHP', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n];\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    // Deliberately unterminated string literal inside the added call.
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n    'endpoint' => env('REPORTING_ENDPOINT\n];\n");
    $repo->commit('add malformed env call');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');

    $findings = null;
    $exception = null;

    try {
        $findings = (new EnvWithoutDefaultRule())->analyze($diff);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($findings)->toBe([]);
});
