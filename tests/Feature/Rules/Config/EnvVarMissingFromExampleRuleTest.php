<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Config\EnvVarMissingFromExampleRule;
use TicoScope\Rules\Config\EnvWithoutDefaultRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzeEnvMissingFromExample(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new EnvVarMissingFromExampleRule($gitDiffReader))->analyze($diff);
}

it('fires for a new env() call in an app/ file referencing an undeclared variable', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('.env.example', "APP_NAME=Laravel\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/PaymentService.php', <<<'PHP'
        <?php

        class PaymentService
        {
            public function key(): string
            {
                return env('PAYMENT_GATEWAY_KEY');
            }
        }
        PHP);
    $repo->commit('add a service referencing an undocumented env var');

    $findings = analyzeEnvMissingFromExample($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('config.env-missing-from-example');
    expect($findings[0]->reasonCode)->toBe('PAYMENT_GATEWAY_KEY');
    expect($findings[0]->message)->toBe(
        'New env() call references "PAYMENT_GATEWAY_KEY", which is not declared in .env.example. A fresh '.
        'checkout, CI runner, or new team member won\'t know this variable is expected unless it\'s documented '.
        'there.',
    );
});

it('does not fire when the variable is declared in .env.example', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('.env.example', "APP_NAME=Laravel\nPAYMENT_GATEWAY_KEY=\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/PaymentService.php', <<<'PHP'
        <?php

        class PaymentService
        {
            public function key(): string
            {
                return env('PAYMENT_GATEWAY_KEY');
            }
        }
        PHP);
    $repo->commit('add a service referencing a documented env var');

    expect(analyzeEnvMissingFromExample($repo))->toBe([]);
});

it('does not fire for a pre-existing, untouched env() call', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('.env.example', "APP_NAME=Laravel\n");
    $repo->writeFile('app/Services/PaymentService.php', <<<'PHP'
        <?php

        class PaymentService
        {
            public function key(): string
            {
                return env('PAYMENT_GATEWAY_KEY');
            }

            public function unrelated(): string
            {
                return 'unrelated';
            }
        }
        PHP);
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/PaymentService.php', <<<'PHP'
        <?php

        class PaymentService
        {
            public function key(): string
            {
                return env('PAYMENT_GATEWAY_KEY');
            }

            public function unrelated(): string
            {
                return 'changed but unrelated';
            }
        }
        PHP);
    $repo->commit('touch an unrelated line only');

    expect(analyzeEnvMissingFromExample($repo))->toBe([]);
});

it('does not fire when .env.example does not exist at head revision at all', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/PaymentService.php', <<<'PHP'
        <?php

        class PaymentService
        {
            public function key(): string
            {
                return env('PAYMENT_GATEWAY_KEY');
            }
        }
        PHP);
    $repo->commit('add a service, no .env.example anywhere in this project');

    expect(analyzeEnvMissingFromExample($repo))->toBe([]);
});

it('does not fire for a non-.php changed file containing the same text pattern', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('.env.example', "APP_NAME=Laravel\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('docs/example.md', "Call `env('PAYMENT_GATEWAY_KEY')` to get the key.\n");
    $repo->commit('add documentation mentioning env() as text');

    expect(analyzeEnvMissingFromExample($repo))->toBe([]);
});

it('does not fire for a deleted file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('.env.example', "APP_NAME=Laravel\n");
    $repo->writeFile('app/Services/PaymentService.php', <<<'PHP'
        <?php

        class PaymentService
        {
            public function key(): string
            {
                return env('PAYMENT_GATEWAY_KEY');
            }
        }
        PHP);
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('app/Services/PaymentService.php');
    $repo->commit('delete the service entirely');

    expect(analyzeEnvMissingFromExample($repo))->toBe([]);
});

it('does not fire when the variable argument is not a literal', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('.env.example', "APP_NAME=Laravel\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/PaymentService.php', <<<'PHP'
        <?php

        class PaymentService
        {
            public function key(string $keyName): string
            {
                return env($keyName);
            }
        }
        PHP);
    $repo->commit('add a service with a dynamic env() key');

    expect(analyzeEnvMissingFromExample($repo))->toBe([]);
});

it('does not fire and does not throw for malformed .env.example content', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('.env.example', "!!! not really valid env format !!!\n@@@ garbage @@@\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/PaymentService.php', <<<'PHP'
        <?php

        class PaymentService
        {
            public function key(): string
            {
                return env('PAYMENT_GATEWAY_KEY');
            }
        }
        PHP);
    $repo->commit('add a service against malformed .env.example content');

    $findings = null;
    $exception = null;

    try {
        $findings = analyzeEnvMissingFromExample($repo);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($findings)->toHaveCount(1);
});

it('does not swallow an unexpected Git/infrastructure failure from readFile()', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('.env.example', "APP_NAME=Laravel\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/PaymentService.php', <<<'PHP'
        <?php

        class PaymentService
        {
            public function key(): string
            {
                return env('PAYMENT_GATEWAY_KEY');
            }
        }
        PHP);
    $repo->commit('add a service referencing an undocumented env var');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new EnvVarMissingFromExampleRule($vanishedReader))->analyze($diff);
})->throws(GitCommandFailedException::class);

it('fires for two distinct undeclared variables added in one file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('.env.example', "APP_NAME=Laravel\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Services/PaymentService.php', <<<'PHP'
        <?php

        class PaymentService
        {
            public function key(): string
            {
                return env('PAYMENT_GATEWAY_KEY');
            }

            public function secret(): string
            {
                return env('PAYMENT_GATEWAY_SECRET');
            }
        }
        PHP);
    $repo->commit('add a service referencing two undocumented env vars');

    $findings = analyzeEnvMissingFromExample($repo);

    expect($findings)->toHaveCount(2);
    $reasonCodes = array_map(fn ($f) => $f->reasonCode, $findings);
    expect($reasonCodes)->toEqualCanonicalizing(['PAYMENT_GATEWAY_KEY', 'PAYMENT_GATEWAY_SECRET']);
});

it('fires alongside EnvWithoutDefaultRule when one new config env() call has no fallback and is undocumented', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('.env.example', "APP_NAME=Laravel\n");
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n];\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('config/services.php', "<?php\n\nreturn [\n    'existing' => 'value',\n    'endpoint' => env('REPORTING_ENDPOINT'),\n];\n");
    $repo->commit('add risky, undocumented env call');

    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare('main', 'feature');

    $missingFindings = (new EnvVarMissingFromExampleRule($gitDiffReader))->analyze($diff);
    $noDefaultFindings = (new EnvWithoutDefaultRule())->analyze($diff);

    expect($missingFindings)->toHaveCount(1);
    expect($missingFindings[0]->ruleId)->toBe('config.env-missing-from-example');
    expect($missingFindings[0]->reasonCode)->toBe('REPORTING_ENDPOINT');
    expect($noDefaultFindings)->toHaveCount(1);
    expect($noDefaultFindings[0]->ruleId)->toBe('config.env-without-default');
});

it('does not fire for a newly added env(\'TESTING_X\') call under tests/', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('.env.example', "APP_NAME=Laravel\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('tests/Feature/ExampleTest.php', <<<'PHP'
        <?php

        it('uses a testing-only env var', function () {
            expect(env('TESTING_X'))->toBeNull();
        });
        PHP);
    $repo->commit('add a test referencing a testing-only env var');

    expect(analyzeEnvMissingFromExample($repo))->toBe([]);
});

it('does not fire when the same diff adds both env(\'FOO\') and its FOO= declaration to .env.example', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('.env.example', "APP_NAME=Laravel\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('.env.example', "APP_NAME=Laravel\nFOO=\n");
    $repo->writeFile('app/Services/FooService.php', <<<'PHP'
        <?php

        class FooService
        {
            public function value(): string
            {
                return env('FOO');
            }
        }
        PHP);
    $repo->commit('add env(FOO) and document FOO in .env.example in the same commit');

    expect(analyzeEnvMissingFromExample($repo))->toBe([]);
});
