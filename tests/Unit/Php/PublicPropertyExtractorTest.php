<?php

use TicoScope\Php\PublicPropertyExtractor;

it('extracts a traditional typed property', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class GenerateReport
        {
            public int $orderId;
        }
        PHP;

    $properties = (new PublicPropertyExtractor())->extract($source);

    expect($properties)->toHaveKey('orderId');
    expect($properties['orderId']->type)->toBe('int');
});

it('extracts an untyped legacy-style property', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class GenerateReport
        {
            public $orderId;
        }
        PHP;

    $properties = (new PublicPropertyExtractor())->extract($source);

    expect($properties)->toHaveKey('orderId');
    expect($properties['orderId']->type)->toBeNull();
});

it('extracts constructor-promoted public properties, typed and untyped', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class GenerateReport
        {
            public function __construct(
                public int $orderId,
                public $note,
                public readonly ?string $label = null,
            ) {
            }
        }
        PHP;

    $properties = (new PublicPropertyExtractor())->extract($source);

    expect($properties)->toHaveKeys(['orderId', 'note', 'label']);
    expect($properties['orderId']->type)->toBe('int');
    expect($properties['note']->type)->toBeNull();
    expect($properties['label']->type)->toBe('?string');
});

it('extracts a mix of promoted and traditional properties in one class', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class GenerateReport
        {
            public string $extra;

            public function __construct(
                public int $orderId,
            ) {
            }
        }
        PHP;

    $properties = (new PublicPropertyExtractor())->extract($source);

    expect($properties)->toHaveKeys(['extra', 'orderId']);
});

it('excludes protected and private properties, traditional and promoted', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class GenerateReport
        {
            protected string $hidden;
            private int $secret;

            public function __construct(
                protected string $promotedHidden,
                private int $promotedSecret,
                public int $visible,
            ) {
            }
        }
        PHP;

    $properties = (new PublicPropertyExtractor())->extract($source);

    expect($properties)->toBe(['visible' => $properties['visible']]);
});

it('excludes a public static property regardless of modifier order', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class GenerateReport
        {
            public static string $defaultConnection = 'redis';
            static public string $legacyDefault = 'old';
            public int $orderId;
        }
        PHP;

    $properties = (new PublicPropertyExtractor())->extract($source);

    expect($properties)->not->toHaveKey('defaultConnection');
    expect($properties)->not->toHaveKey('legacyDefault');
    expect($properties)->toHaveKey('orderId');
});

it('does not mistake a method declaration for a property', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class GenerateReport
        {
            public int $orderId;

            public function handle(): void
            {
            }
        }
        PHP;

    $properties = (new PublicPropertyExtractor())->extract($source);

    expect($properties)->toBe(['orderId' => $properties['orderId']]);
});

it('does not mistake a local variable inside a method body for a class-level property', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class GenerateReport
        {
            public function handle(): void
            {
                $connection = 'local';
                $this->dispatch($connection);
            }
        }
        PHP;

    $properties = (new PublicPropertyExtractor())->extract($source);

    expect($properties)->toBe([]);
});

it('uses the first class declaration when a file declares more than one', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class First
        {
            public int $a;
        }

        class Second
        {
            public int $b;
        }
        PHP;

    $properties = (new PublicPropertyExtractor())->extract($source);

    expect($properties)->toHaveKey('a');
    expect($properties)->not->toHaveKey('b');
});

it('returns null for malformed or incomplete source', function () {
    $source = "<?php\n\nnamespace App\\Jobs;\n\nclass Gene";

    $result = null;
    $exception = null;

    try {
        $result = (new PublicPropertyExtractor())->extract($source);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($result)->toBeNull();
});

it('returns an empty array, not null, for a class with zero public properties', function () {
    $source = <<<'PHP'
        <?php

        namespace App\Jobs;

        class GenerateReport
        {
            private int $secret;

            public function handle(): void
            {
            }
        }
        PHP;

    $properties = (new PublicPropertyExtractor())->extract($source);

    expect($properties)->toBe([]);
});
