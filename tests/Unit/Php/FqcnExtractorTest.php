<?php

use TicoScope\Php\FqcnExtractor;

it('extracts a namespaced class', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class GenerateReport
        {
        }
        PHP;

    expect((new FqcnExtractor())->extract($source))->toBe('App\Jobs\GenerateReport');
});

it('extracts a single-segment namespace', function () {
    $source = <<<'PHP'
        <?php

        namespace App;

        class GenerateReport
        {
        }
        PHP;

    expect((new FqcnExtractor())->extract($source))->toBe('App\GenerateReport');
});

it('extracts a bare class name when there is no namespace statement', function () {
    $source = <<<'PHP'
        <?php

        class GenerateReport
        {
        }
        PHP;

    expect((new FqcnExtractor())->extract($source))->toBe('GenerateReport');
});

it('extracts a bare class name for an explicit global namespace', function () {
    $source = <<<'PHP'
        <?php

        namespace;

        class GenerateReport
        {
        }
        PHP;

    expect((new FqcnExtractor())->extract($source))->toBe('GenerateReport');
});

it('does not mistake ::class fetches or anonymous classes for a declaration', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class GenerateReport
        {
            public function handle(): void
            {
                $x = SomeOther::class;
                $y = new class extends SomeBase {};
            }
        }
        PHP;

    expect((new FqcnExtractor())->extract($source))->toBe('App\Jobs\GenerateReport');
});

it('returns null when the file has no real class declaration', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        $callback = function () {
            return SomeOther::class;
        };
        PHP;

    expect((new FqcnExtractor())->extract($source))->toBeNull();
});

it('uses the first class declaration when a file declares more than one', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class First
        {
        }

        class Second
        {
        }
        PHP;

    expect((new FqcnExtractor())->extract($source))->toBe('App\Jobs\First');
});

it('returns null for brace-block namespace syntax rather than guessing', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs {
            class GenerateReport
            {
            }
        }
        PHP;

    expect((new FqcnExtractor())->extract($source))->toBeNull();
});

it('completes without throwing for malformed or incomplete source', function () {
    $source = "<?php\n\nnamespace App\\Jobs\n\nclass Gene";

    $result = null;
    $exception = null;

    try {
        $result = (new FqcnExtractor())->extract($source);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($result)->toBeNull();
});
