<?php

use TicoScope\Php\SchemaCallExtractor;

it('extracts a simple dropColumn call inside Schema::table()', function () {
    $source = <<<'PHP'
        <?php

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('legacy_reference');
        });
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations)->toHaveCount(1);
    expect($analysis->tableOperations[0]->table)->toBe('orders');
    expect($analysis->tableOperations[0]->statements)->toBe([
        [['method' => 'dropColumn', 'args' => ['legacy_reference']]],
    ]);
});

it('extracts direct Schema::drop() and Schema::dropIfExists() calls', function () {
    $source = <<<'PHP'
        <?php

        Schema::drop('orders');
        Schema::dropIfExists('customers');
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->droppedTables)->toBe(['orders', 'customers']);
});

it('recognizes a fully-qualified Schema:: reference the same as the bare form', function () {
    $source = <<<'PHP'
        <?php

        \Illuminate\Support\Facades\Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('legacy_reference');
        });

        \Illuminate\Support\Facades\Schema::dropIfExists('customers');
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations)->toHaveCount(1);
    expect($analysis->tableOperations[0]->table)->toBe('orders');
    expect($analysis->droppedTables)->toBe(['customers']);
});

it('handles a multi-line fluent chain', function () {
    $source = <<<'PHP'
        <?php

        Schema::table('orders', function (Blueprint $table) {
            $table->string('description', 100)
                ->nullable()
                ->change();
        });
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations[0]->statements)->toBe([
        [
            ['method' => 'string', 'args' => ['description', null]],
            ['method' => 'nullable', 'args' => []],
            ['method' => 'change', 'args' => []],
        ],
    ]);
});

it('tracks whatever the closure parameter is actually named, not just $table', function () {
    $source = <<<'PHP'
        <?php

        Schema::table('orders', function (Blueprint $blueprint) {
            $blueprint->dropColumn('legacy_reference');
        });
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations[0]->statements)->toBe([
        [['method' => 'dropColumn', 'args' => ['legacy_reference']]],
    ]);
});

it('does not require a Blueprint type hint on the closure parameter', function () {
    $source = <<<'PHP'
        <?php

        Schema::table('orders', function ($table) {
            $table->dropColumn('legacy_reference');
        });
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations[0]->statements)->toBe([
        [['method' => 'dropColumn', 'args' => ['legacy_reference']]],
    ]);
});

it('scopes multiple Schema::table()/create() calls in one file independently', function () {
    $source = <<<'PHP'
        <?php

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('legacy_reference');
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
        });
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations)->toHaveCount(2);
    expect($analysis->tableOperations[0]->table)->toBe('orders');
    expect($analysis->tableOperations[1]->table)->toBe('customers');
});

it('resolves an array-argument dropColumn(["a", "b"]) to both names', function () {
    $source = <<<'PHP'
        <?php

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['legacy_a', 'legacy_b']);
        });
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations[0]->statements)->toBe([
        [['method' => 'dropColumn', 'args' => [['legacy_a', 'legacy_b']]]],
    ]);
});

it('leaves a variable column-name argument unresolved rather than guessing', function () {
    $source = <<<'PHP'
        <?php

        Schema::create('translations', function (Blueprint $table) {
            foreach (['en', 'fr'] as $locale) {
                $table->string($locale);
            }
        });
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations[0]->statements)->toBe([
        [['method' => 'string', 'args' => [null]]],
    ]);
});

it('does not attribute a shadowed nested-closure variable to the outer Blueprint', function () {
    // The exact false-positive scenario confirmed by the feasibility spike:
    // the inner closure's own $table parameter is unrelated to the
    // Blueprint, and must not be picked up as if it were.
    $source = <<<'PHP'
        <?php

        Schema::table('orders', function (Blueprint $table) {
            $rows = DB::table('legacy_data')->get();

            $rows->each(function ($table) {
                $table->markProcessed();
            });

            $table->string('status');
        });
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations[0]->statements)->toBe([
        [['method' => 'string', 'args' => ['status']]],
    ]);
});

it('descends into a nested closure that explicitly captures the Blueprint via use()', function () {
    $source = <<<'PHP'
        <?php

        Schema::table('orders', function (Blueprint $table) {
            DB::transaction(function () use ($table) {
                $table->foreignId('customer_id')->nullable();
            });
        });
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations[0]->statements)->toBe([
        [
            ['method' => 'foreignId', 'args' => ['customer_id']],
            ['method' => 'nullable', 'args' => []],
        ],
    ]);
});

it('does not descend into a nested closure that does not capture the Blueprint at all', function () {
    $source = <<<'PHP'
        <?php

        Schema::table('orders', function (Blueprint $table) {
            $table->string('status');

            DB::transaction(function () {
                $table->dropColumn('should_not_count');
            });
        });
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations[0]->statements)->toBe([
        [['method' => 'string', 'args' => ['status']]],
    ]);
});

it('descends into a nested arrow function, which auto-captures', function () {
    $source = <<<'PHP'
        <?php

        Schema::table('orders', function (Blueprint $table) {
            $callback = fn () => $table->dropColumn('legacy_reference');
        });
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations[0]->statements)->toBe([
        [['method' => 'dropColumn', 'args' => ['legacy_reference']]],
    ]);
});

it('does not attribute a shadowed nested arrow function variable to the outer Blueprint', function () {
    $source = <<<'PHP'
        <?php

        Schema::table('orders', function (Blueprint $table) {
            $items->each(fn ($table) => $table->markProcessed());

            $table->string('status');
        });
        PHP;

    $analysis = (new SchemaCallExtractor())->extract($source);

    expect($analysis->tableOperations[0]->statements)->toBe([
        [['method' => 'string', 'args' => ['status']]],
    ]);
});

it('returns null for malformed or incomplete source', function () {
    $source = "<?php\n\nSchema::table('orders', function (Blueprint";

    $result = null;
    $exception = null;

    try {
        $result = (new SchemaCallExtractor())->extract($source);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($result)->not->toBeNull();
    expect($result->tableOperations)->toBe([]);
    expect($result->droppedTables)->toBe([]);
});
