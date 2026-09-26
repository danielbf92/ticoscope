<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Migration\ColumnDroppedRule;
use TicoScope\Rules\Migration\ColumnRenamedRule;
use TicoScope\Rules\Migration\ColumnTypeChangedRule;
use TicoScope\Rules\Migration\NonNullableWithoutDefaultRule;
use TicoScope\Rules\Migration\TableDroppedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzeColumnTypeChanged(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new ColumnTypeChangedRule($gitDiffReader))->analyze($diff);
}

it('fires for a newly added migration redefining a column via ->change()', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_narrow_reference.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('reference', 50)->change();
                });
            }
        };
        PHP);
    $repo->commit('narrow an existing column');

    $findings = analyzeColumnTypeChanged($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('migration.column-type-changed');
    expect($findings[0]->reasonCode)->toBe('reference');
    expect($findings[0]->message)->toBe(
        'Migration redefines column "reference" on table "orders" via ->change(). TicoScope cannot see the '.
        'column\'s previous definition from this migration alone — if the new definition is narrower (a '.
        'shorter string length, a smaller integer size, reduced decimal precision, etc.), this can silently '.
        'truncate existing data. Verify the previous column definition before deploying.',
    );
});

it('fires for a modified migration redefining a column via ->change()', function () {
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
                    $table->integer('quantity')->change();
                });
            }
        };
        PHP);
    $repo->commit('edit migration before it has run');

    $findings = analyzeColumnTypeChanged($repo);

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
                    $table->string('status', 50)->change();
                });
            }
        };
        PHP);
    $repo->commit('add a brand new table');

    expect(analyzeColumnTypeChanged($repo))->toBe([]);
});

it('does not fire when there is no ->change() call at all', function () {
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
    $repo->commit('add a plain new column, no ->change()');

    expect(analyzeColumnTypeChanged($repo))->toBe([]);
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
                        $table->string($name)->change();
                    }
                });
            }
        };
        PHP);
    $repo->commit('redefine columns with a non-literal name');

    expect(analyzeColumnTypeChanged($repo))->toBe([]);
});

it('does not fire when the first call is not a recognized column-type method', function () {
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
                    $table->dropColumn('legacy_reference')->change();
                });
            }
        };
        PHP);
    $repo->commit('an unrecognized method chained with change()');

    expect(analyzeColumnTypeChanged($repo))->toBe([]);
});

it('fires once per column when two separate columns are redefined via ->change()', function () {
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
                    $table->string('first_name', 50)->change();
                    $table->string('last_name', 50)->change();
                });
            }
        };
        PHP);
    $repo->commit('redefine two columns');

    $findings = analyzeColumnTypeChanged($repo);

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
                    $table->string('reference', 50)->change();
                });
            }
        }
        PHP);
    $repo->commit('add a non-migration file with the same pattern');

    expect(analyzeColumnTypeChanged($repo))->toBe([]);
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
                    $table->string('reference', 50)->change();
                });
            }
        };
        PHP);
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('database/migrations/2026_01_01_000000_example.php');
    $repo->commit('delete migration');

    expect(analyzeColumnTypeChanged($repo))->toBe([]);
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
        $findings = analyzeColumnTypeChanged($repo);
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
                    $table->string('reference', 50)->change();
                });
            }
        };
        PHP);
    $repo->commit('narrow an existing column');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new ColumnTypeChangedRule($vanishedReader))->analyze($diff);
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
                        $table->string('should_not_count', 10)->change();
                    });

                    $table->string('status');
                });
            }
        };
        PHP);
    $repo->commit('add migration with a shadowed variable');

    expect(analyzeColumnTypeChanged($repo))->toBe([]);
});

it('fires alongside every other Migration rule when one migration does all five things', function () {
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
                    $table->integer('quantity')->change();
                });

                Schema::dropIfExists('legacy_orders');
            }
        };
        PHP);
    $repo->commit('add, rename, drop, change a column, and drop a table in one migration');

    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare('main', 'feature');

    $typeChangedFindings = (new ColumnTypeChangedRule($gitDiffReader))->analyze($diff);
    $addedFindings = (new NonNullableWithoutDefaultRule($gitDiffReader))->analyze($diff);
    $renamedFindings = (new ColumnRenamedRule($gitDiffReader))->analyze($diff);
    $droppedFindings = (new ColumnDroppedRule($gitDiffReader))->analyze($diff);
    $tableFindings = (new TableDroppedRule($gitDiffReader))->analyze($diff);

    expect($typeChangedFindings)->toHaveCount(1);
    expect($typeChangedFindings[0]->ruleId)->toBe('migration.column-type-changed');
    expect($typeChangedFindings[0]->reasonCode)->toBe('quantity');
    expect($addedFindings)->toHaveCount(1);
    expect($renamedFindings)->toHaveCount(1);
    expect($droppedFindings)->toHaveCount(1);
    expect($tableFindings)->toHaveCount(1);
});

it('M10/M11 boundary: $table->string("foo", 50)->change() fires in M11 only, never M10', function () {
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
                    $table->string('foo', 50)->change();
                });
            }
        };
        PHP);
    $repo->commit('narrow an existing column via the exact M10/M11 boundary fixture');

    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare('main', 'feature');

    $m10Findings = (new NonNullableWithoutDefaultRule($gitDiffReader))->analyze($diff);
    $m11Findings = (new ColumnTypeChangedRule($gitDiffReader))->analyze($diff);

    expect($m10Findings)->toBe([]);
    expect($m11Findings)->toHaveCount(1);
    expect($m11Findings[0]->ruleId)->toBe('migration.column-type-changed');
    expect($m11Findings[0]->reasonCode)->toBe('foo');
});
