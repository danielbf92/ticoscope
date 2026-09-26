<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Migration\ColumnDroppedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzeColumnDropped(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new ColumnDroppedRule($gitDiffReader))->analyze($diff);
}

it('fires for a newly added migration that drops a column', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_drop_legacy_reference.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropColumn('legacy_reference');
                });
            }
        };
        PHP);
    $repo->commit('add migration dropping a column');

    $findings = analyzeColumnDropped($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('migration.column-dropped');
    expect($findings[0]->reasonCode)->toBe('legacy_reference');
    expect($findings[0]->message)->toBe(
        'Migration drops column "legacy_reference" on table "orders". This is a destructive, typically '.
        'irreversible operation. Confirm a backup/rollback plan exists.',
    );
});

it('fires for a modified migration that drops a column', function () {
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
                    $table->dropColumn('note');
                });
            }
        };
        PHP);
    $repo->commit('edit migration before it has run');

    $findings = analyzeColumnDropped($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->reasonCode)->toBe('note');
});

it('fires once per column for an array-argument dropColumn call', function () {
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
                    $table->dropColumn(['legacy_a', 'legacy_b']);
                });
            }
        };
        PHP);
    $repo->commit('add migration dropping two columns');

    $findings = analyzeColumnDropped($repo);

    expect($findings)->toHaveCount(2);
    $reasonCodes = array_map(fn ($f) => $f->reasonCode, $findings);
    expect($reasonCodes)->toEqualCanonicalizing(['legacy_a', 'legacy_b']);
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

    expect(analyzeColumnDropped($repo))->toBe([]);
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
                    $table->dropColumn('legacy_reference');
                });
            }
        }
        PHP);
    $repo->commit('add a non-migration file with the same pattern');

    expect(analyzeColumnDropped($repo))->toBe([]);
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
                    $table->dropColumn('legacy_reference');
                });
            }
        };
        PHP);
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('database/migrations/2026_01_01_000000_example.php');
    $repo->commit('delete migration');

    expect(analyzeColumnDropped($repo))->toBe([]);
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
        $findings = analyzeColumnDropped($repo);
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
                    $table->dropColumn('legacy_reference');
                });
            }
        };
        PHP);
    $repo->commit('add migration dropping a column');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new ColumnDroppedRule($vanishedReader))->analyze($diff);
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
                        $table->dropColumn('should_not_count');
                    });

                    $table->string('status');
                });
            }
        };
        PHP);
    $repo->commit('add migration with a shadowed variable');

    expect(analyzeColumnDropped($repo))->toBe([]);
});
