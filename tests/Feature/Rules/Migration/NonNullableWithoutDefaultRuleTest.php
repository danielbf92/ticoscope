<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Migration\ColumnDroppedRule;
use TicoScope\Rules\Migration\ColumnRenamedRule;
use TicoScope\Rules\Migration\NonNullableWithoutDefaultRule;
use TicoScope\Rules\Migration\TableDroppedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzeNonNullableWithoutDefault(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new NonNullableWithoutDefaultRule($gitDiffReader))->analyze($diff);
}

it('fires for a newly added migration adding a non-nullable column with no default', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_add_reference.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('reference');
                });
            }
        };
        PHP);
    $repo->commit('add migration adding a risky column');

    $findings = analyzeNonNullableWithoutDefault($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('migration.non-nullable-without-default');
    expect($findings[0]->reasonCode)->toBe('reference');
    expect($findings[0]->message)->toBe(
        'Migration adds column "reference" to table "orders" with no nullable() call and no default(). If '.
        '"orders" already has rows, this may fail outright or behave unexpectedly depending on your database\'s '.
        'strict mode. Add ->nullable() or ->default(...), or confirm the table is empty in every environment '.
        'this runs against.',
    );
});

it('fires for a modified migration adding a non-nullable column with no default', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('note')->nullable();
                });
            }
        };
        PHP);
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->integer('quantity');
                });
            }
        };
        PHP);
    $repo->commit('edit migration before it has run');

    $findings = analyzeNonNullableWithoutDefault($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->reasonCode)->toBe('quantity');
});

it('does not fire when Schema::create() is used instead of Schema::table()', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('orders', function (Blueprint $table) {
                    $table->id();
                    $table->string('status');
                });
            }
        };
        PHP);
    $repo->commit('add a brand new table');

    expect(analyzeNonNullableWithoutDefault($repo))->toBe([]);
});

it('does not fire when the chain includes ->nullable()', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('reference')->nullable();
                });
            }
        };
        PHP);
    $repo->commit('add a nullable column');

    expect(analyzeNonNullableWithoutDefault($repo))->toBe([]);
});

it('does not fire when the chain includes ->nullable(false), a documented false-negative', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('reference')->nullable(false);
                });
            }
        };
        PHP);
    $repo->commit('add a column with an explicit nullable(false)');

    expect(analyzeNonNullableWithoutDefault($repo))->toBe([]);
});

it('does not fire when the chain includes ->default(...)', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->boolean('is_active')->default(true);
                });
            }
        };
        PHP);
    $repo->commit('add a column with a default');

    expect(analyzeNonNullableWithoutDefault($repo))->toBe([]);
});

it('does not fire for timestamp() with ->useCurrent()', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->timestamp('processed_at')->useCurrent();
                });
            }
        };
        PHP);
    $repo->commit('add a timestamp defaulting to current time');

    expect(analyzeNonNullableWithoutDefault($repo))->toBe([]);
});

it('does not fire for excluded macros on an existing table (timestamps, softDeletes, rememberToken, id)', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->id();
                    $table->timestamps();
                    $table->softDeletes();
                    $table->rememberToken();
                });
            }
        };
        PHP);
    $repo->commit('add only excluded macro columns');

    expect(analyzeNonNullableWithoutDefault($repo))->toBe([]);
});

it('does not fire when the chain ends in ->change() (altering, not adding)', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('reference', 500)->change();
                });
            }
        };
        PHP);
    $repo->commit('alter an existing column');

    expect(analyzeNonNullableWithoutDefault($repo))->toBe([]);
});

it('does not fire when the column-name argument is not a literal', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    foreach (['a', 'b'] as $name) {
                        $table->string($name);
                    }
                });
            }
        };
        PHP);
    $repo->commit('add columns with a non-literal name');

    expect(analyzeNonNullableWithoutDefault($repo))->toBe([]);
});

it('fires once per column when two separate non-nullable columns are added', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('customers', function (Blueprint $table) {
                    $table->string('first_name');
                    $table->string('last_name');
                });
            }
        };
        PHP);
    $repo->commit('add two risky columns');

    $findings = analyzeNonNullableWithoutDefault($repo);

    expect($findings)->toHaveCount(2);
    $reasonCodes = array_map(fn ($f) => $f->reasonCode, $findings);
    expect($reasonCodes)->toEqualCanonicalizing(['first_name', 'last_name']);
});

it('does not fire for a non-Migration-classified file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Support/SchemaHelper.php', <<<'PHP'
        <?php

        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        class SchemaHelper
        {
            public function run(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('reference');
                });
            }
        }
        PHP);
    $repo->commit('add a non-migration file with the same pattern');

    expect(analyzeNonNullableWithoutDefault($repo))->toBe([]);
});

it('does not fire for a deleted migration', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('reference');
                });
            }
        };
        PHP);
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('database/migrations/2026_01_01_000000_example.php');
    $repo->commit('delete migration');

    expect(analyzeNonNullableWithoutDefault($repo))->toBe([]);
});

it('does not fire and does not throw for malformed migration content', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', "<?php\n\nSchema::table('orders', function (Blueprint");
    $repo->commit('add malformed migration');

    $findings = null;
    $exception = null;

    try {
        $findings = analyzeNonNullableWithoutDefault($repo);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeNull();
    expect($findings)->toBe([]);
});

it('does not swallow an unexpected Git/infrastructure failure from readFile()', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('reference');
                });
            }
        };
        PHP);
    $repo->commit('add migration adding a risky column');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new NonNullableWithoutDefaultRule($vanishedReader))->analyze($diff);
})->throws(GitCommandFailedException::class);

it('does not attribute a shadowed nested-closure variable at the rule level either', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $rows = DB::table('legacy_data')->get();

                    $rows->each(function ($table) {
                        $table->string('should_not_count');
                    });

                    $table->string('status')->nullable();
                });
            }
        };
        PHP);
    $repo->commit('add migration with a shadowed variable');

    expect(analyzeNonNullableWithoutDefault($repo))->toBe([]);
});

it('fires alongside ColumnDroppedRule, ColumnRenamedRule, and TableDroppedRule when one migration does all four things', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('reference');
                    $table->renameColumn('note', 'notes');
                    $table->dropColumn('unused');
                });

                Schema::dropIfExists('legacy_orders');
            }
        };
        PHP);
    $repo->commit('add, rename, drop a column, and drop a table in one migration');

    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare('main', 'feature');

    $addedFindings = (new NonNullableWithoutDefaultRule($gitDiffReader))->analyze($diff);
    $renamedFindings = (new ColumnRenamedRule($gitDiffReader))->analyze($diff);
    $droppedFindings = (new ColumnDroppedRule($gitDiffReader))->analyze($diff);
    $tableFindings = (new TableDroppedRule($gitDiffReader))->analyze($diff);

    expect($addedFindings)->toHaveCount(1);
    expect($addedFindings[0]->ruleId)->toBe('migration.non-nullable-without-default');
    expect($renamedFindings)->toHaveCount(1);
    expect($droppedFindings)->toHaveCount(1);
    expect($tableFindings)->toHaveCount(1);
});
