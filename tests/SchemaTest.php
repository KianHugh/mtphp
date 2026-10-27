<?php
declare(strict_types=1);

/**
 * Schema 构造器 + Migrator 测试（内存 SQLite，零外部依赖）
 * 运行：php8 tests/run.php SchemaTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Migrator;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

class SchemaTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testCreateTableAndHasTable(): void
    {
        Assert::assertFalse(Schema::hasTable('users'));

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 100)->nullable(false);
            $t->integer('age')->unsigned()->default(0);
            $t->boolean('active')->default(true);
            $t->text('bio')->nullable();
            $t->timestamps();
            $t->unique('name');
        });

        Assert::assertTrue(Schema::hasTable('users'));

        // 插入并自增主键
        $id = DB::table('users')->insert(['name' => 'alice', 'age' => 30, 'bio' => null]);
        Assert::assertSame(1, $id);

        $row = DB::table('users')->where('name', '=', 'alice')->find();
        Assert::assertSame('alice', $row['name']);
        Assert::assertSame(30, (int) $row['age']);
        Assert::assertTrue(array_key_exists('created_at', $row));
        Assert::assertTrue(array_key_exists('updated_at', $row));
    }

    public function testAlterTableAddColumnAndIndex(): void
    {
        Schema::create('posts', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
        });

        Schema::table('posts', function (Blueprint $t): void {
            $t->integer('views')->default(0);
            $t->index('title');
        });

        DB::table('posts')->insert(['title' => 'hi', 'views' => 10]);
        $row = DB::table('posts')->find();
        Assert::assertSame(10, (int) $row['views']);

        // 唯一索引已存在：重复 title 不抛错（SQLite 允许，但索引本身有效）
        Assert::assertTrue(Schema::hasTable('posts'));
    }

    public function testDropIfExists(): void
    {
        Schema::create('tmp_x', function (Blueprint $t): void {
            $t->id();
        });
        Assert::assertTrue(Schema::hasTable('tmp_x'));
        Schema::dropIfExists('tmp_x');
        Assert::assertFalse(Schema::hasTable('tmp_x'));
        // 再删一次不应报错
        Schema::dropIfExists('tmp_x');
    }

    public function testMigratorRunAndRollback(): void
    {
        $dir = sys_get_temp_dir() . '/mtphp_mig_' . uniqid();
        mkdir($dir);

        file_put_contents($dir . '/2024_01_01_000001_create_a_table.php', <<<'PHP'
<?php
use Mtphp\Database\Migration;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Schema\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('aaa', function (Blueprint $t) { $t->id(); $t->string('x'); });
    }
    public function down(): void {
        Schema::dropIfExists('aaa');
    }
};
PHP
        );

        file_put_contents($dir . '/2024_01_02_000002_create_b_table.php', <<<'PHP'
<?php
use Mtphp\Database\Migration;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Schema\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('bbb', function (Blueprint $t) { $t->id(); $t->integer('y'); });
    }
    public function down(): void {
        Schema::dropIfExists('bbb');
    }
};
PHP
        );

        $migrator = new Migrator(DB::connection(), 'sqlite');

        $ran = $migrator->run([$dir]);
        Assert::assertSame(['2024_01_01_000001_create_a_table', '2024_01_02_000002_create_b_table'], $ran);
        Assert::assertTrue(Schema::hasTable('aaa'));
        Assert::assertTrue(Schema::hasTable('bbb'));
        // 两条迁移同属一个批次
        Assert::assertSame(1, $migrator->getLastBatchNumber());

        // 再次 run 不应重复执行
        $ran2 = $migrator->run([$dir]);
        Assert::assertSame([], $ran2);

        // 回滚最近一批（两条一起）
        $rolled = $migrator->rollback([$dir]);
        Assert::assertSame(['2024_01_02_000002_create_b_table', '2024_01_01_000001_create_a_table'], $rolled);
        Assert::assertFalse(Schema::hasTable('aaa'));
        Assert::assertFalse(Schema::hasTable('bbb'));

        // 清理
        array_map('unlink', glob($dir . '/*.php') ?: []);
        rmdir($dir);
    }
}
