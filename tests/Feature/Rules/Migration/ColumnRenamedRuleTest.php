<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Migration\ColumnDroppedRule;
use TicoScope\Rules\Migration\ColumnRenamedRule;
use TicoScope\Rules\Migration\TableDroppedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzeColumnRenamed(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new ColumnRenamedRule($gitDiffReader))->analyze($diff);
}

it('fires for a newly added migration that renames a column', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_rename_legacy_reference.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->renameColumn('legacy_reference', 'reference');
                });
            }
        };
        PHP);
    $repo->commit('add migration renaming a column');

    $findings = analyzeColumnRenamed($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('migration.column-renamed');
    expect($findings[0]->reasonCode)->toBe('legacy_reference');
    expect($findings[0]->message)->toBe(
        'Migration renames column "legacy_reference" to "reference" on table "orders". Application code and '.
        'queries still referencing "legacy_reference" will break as soon as this migration runs.',
    );
});

it('fires for a modified migration that renames a column', function () {
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
                    $table->renameColumn('note', 'notes');
                });
            }
        };
        PHP);
    $repo->commit('edit migration before it has run');

    $findings = analyzeColumnRenamed($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->reasonCode)->toBe('note');
});

it('fires once per renameColumn call when a migration renames two columns', function () {
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
                    $table->renameColumn('legacy_a', 'a');
                    $table->renameColumn('legacy_b', 'b');
                });
            }
        };
        PHP);
    $repo->commit('add migration renaming two columns');

    $findings = analyzeColumnRenamed($repo);

    expect($findings)->toHaveCount(2);
    $reasonCodes = array_map(fn ($f) => $f->reasonCode, $findings);
    expect($reasonCodes)->toEqualCanonicalizing(['legacy_a', 'legacy_b']);
});

it('does not fire when the old or new argument is not a literal', function () {
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
                    $newName = 'reference';
                    $table->renameColumn('legacy_reference', $newName);
                });
            }
        };
        PHP);
    $repo->commit('add migration with a non-literal rename argument');

    expect(analyzeColumnRenamed($repo))->toBe([]);
});

it('does not fire for a migration with no risky operations', function () {
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
    $repo->commit('add a harmless migration');

    expect(analyzeColumnRenamed($repo))->toBe([]);
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
                    $table->renameColumn('legacy_reference', 'reference');
                });
            }
        }
        PHP);
    $repo->commit('add a non-migration file with the same pattern');

    expect(analyzeColumnRenamed($repo))->toBe([]);
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
                    $table->renameColumn('legacy_reference', 'reference');
                });
            }
        };
        PHP);
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('database/migrations/2026_01_01_000000_example.php');
    $repo->commit('delete migration');

    expect(analyzeColumnRenamed($repo))->toBe([]);
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
        $findings = analyzeColumnRenamed($repo);
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
                    $table->renameColumn('legacy_reference', 'reference');
                });
            }
        };
        PHP);
    $repo->commit('add migration renaming a column');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new ColumnRenamedRule($vanishedReader))->analyze($diff);
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
                        $table->renameColumn('should_not_count', 'renamed');
                    });

                    $table->string('status');
                });
            }
        };
        PHP);
    $repo->commit('add migration with a shadowed variable');

    expect(analyzeColumnRenamed($repo))->toBe([]);
});

it('fires alongside ColumnDroppedRule and TableDroppedRule when one migration does all three', function () {
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
                    $table->renameColumn('legacy_reference', 'reference');
                    $table->dropColumn('unused');
                });

                Schema::dropIfExists('legacy_orders');
            }
        };
        PHP);
    $repo->commit('rename a column, drop a column, and drop a table in one migration');

    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare('main', 'feature');

    $renamedFindings = (new ColumnRenamedRule($gitDiffReader))->analyze($diff);
    $columnFindings = (new ColumnDroppedRule($gitDiffReader))->analyze($diff);
    $tableFindings = (new TableDroppedRule($gitDiffReader))->analyze($diff);

    expect($renamedFindings)->toHaveCount(1);
    expect($renamedFindings[0]->ruleId)->toBe('migration.column-renamed');
    expect($columnFindings)->toHaveCount(1);
    expect($columnFindings[0]->ruleId)->toBe('migration.column-dropped');
    expect($tableFindings)->toHaveCount(1);
    expect($tableFindings[0]->ruleId)->toBe('migration.table-dropped');
});
