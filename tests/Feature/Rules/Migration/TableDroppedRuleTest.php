<?php

use TicoScope\Git\GitCommandFailedException;
use TicoScope\Git\GitDiffReader;
use TicoScope\Rules\Migration\ColumnDroppedRule;
use TicoScope\Rules\Migration\TableDroppedRule;
use TicoScope\Tests\Support\TemporaryGitRepository;

function analyzeTableDropped(TemporaryGitRepository $repo, string $base = 'main', string $head = 'feature'): array
{
    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare($base, $head);

    return (new TableDroppedRule($gitDiffReader))->analyze($diff);
}

it('fires for a newly added migration that drops a table', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_drop_legacy_table.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::dropIfExists('legacy_orders');
            }
        };
        PHP);
    $repo->commit('add migration dropping a table');

    $findings = analyzeTableDropped($repo);

    expect($findings)->toHaveCount(1);
    expect($findings[0]->ruleId)->toBe('migration.table-dropped');
    expect($findings[0]->reasonCode)->toBe('legacy_orders');
    expect($findings[0]->message)->toBe(
        'Migration drops table "legacy_orders". This is a destructive, typically irreversible operation. '.
        'Confirm a backup/rollback plan exists.',
    );
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
                });
            }
        };
        PHP);
    $repo->commit('add a harmless migration');

    expect(analyzeTableDropped($repo))->toBe([]);
});

it('does not fire for a non-Migration-classified file', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('app/Support/SchemaHelper.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nSchema::drop('legacy_orders');\n");
    $repo->commit('add a non-migration file with the same pattern');

    expect(analyzeTableDropped($repo))->toBe([]);
});

it('does not fire for a deleted migration', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nSchema::drop('legacy_orders');\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->deleteFile('database/migrations/2026_01_01_000000_example.php');
    $repo->commit('delete migration');

    expect(analyzeTableDropped($repo))->toBe([]);
});

it('does not fire and does not throw for malformed migration content', function () {
    $repo = new TemporaryGitRepository();
    $repo->writeFile('app/Existing.php', "<?php\n// existing\n");
    $repo->commit('base');

    $repo->checkoutNewBranch('feature');
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', "<?php\n\nSchema::dropIfExists('leg");
    $repo->commit('add malformed migration');

    $findings = null;
    $exception = null;

    try {
        $findings = analyzeTableDropped($repo);
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
    $repo->writeFile('database/migrations/2026_01_01_000000_example.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nSchema::dropIfExists('legacy_orders');\n");
    $repo->commit('add migration dropping a table');

    $diff = (new GitDiffReader($repo->path()))->compare('main', 'feature');
    $vanishedReader = new GitDiffReader(sys_get_temp_dir().'/ticoscope-vanished-'.uniqid());

    (new TableDroppedRule($vanishedReader))->analyze($diff);
})->throws(GitCommandFailedException::class);

it('fires alongside ColumnDroppedRule when one migration does both', function () {
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

                Schema::dropIfExists('legacy_orders');
            }
        };
        PHP);
    $repo->commit('drop a column and a table in one migration');

    $gitDiffReader = new GitDiffReader($repo->path());
    $diff = $gitDiffReader->compare('main', 'feature');

    $columnFindings = (new ColumnDroppedRule($gitDiffReader))->analyze($diff);
    $tableFindings = (new TableDroppedRule($gitDiffReader))->analyze($diff);

    expect($columnFindings)->toHaveCount(1);
    expect($columnFindings[0]->ruleId)->toBe('migration.column-dropped');
    expect($tableFindings)->toHaveCount(1);
    expect($tableFindings[0]->ruleId)->toBe('migration.table-dropped');
});
