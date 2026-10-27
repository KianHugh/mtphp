<?php
declare(strict_types=1);

/**
 * 便捷写方法（firstOrCreate / updateOrCreate / firstOrNew）测试（内存 SQLite）
 * 运行：php tests/run.php FirstOrCreateTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

class FocUser extends Model
{
    protected static ?string $table = 'foc_users';
    protected static string $primaryKey = 'id';
}

class FirstOrCreateTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('foc_users', function (Blueprint $t): void {
            $t->id();
            $t->string('email');
            $t->string('name');
            $t->integer('age');
        });

        FocUser::create(['email' => 'a@b.com', 'name' => 'Alice', 'age' => 20]);
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testFirstOrCreateFindsExisting(): void
    {
        $row = FocUser::firstOrCreate(['email' => 'a@b.com'], ['name' => 'Ignored']);
        Assert::assertSame('Alice', $row['name']);
        Assert::assertSame(20, $row['age']);
        // 不应新增行
        Assert::assertSame(1, FocUser::count());
    }

    public function testFirstOrCreateCreatesMissing(): void
    {
        $row = FocUser::firstOrCreate(['email' => 'c@d.com'], ['name' => 'Carol', 'age' => 30]);
        Assert::assertSame('Carol', $row['name']);
        Assert::assertSame(30, $row['age']);
        Assert::assertNotNull($row['id'] ?? null);
        Assert::assertSame(2, FocUser::count());
        // 落库确认
        $fresh = FocUser::find($row['id']);
        Assert::assertSame('c@d.com', $fresh['email']);
    }

    public function testUpdateOrCreateUpdatesExisting(): void
    {
        $row = FocUser::updateOrCreate(['email' => 'a@b.com'], ['name' => 'Alice2', 'age' => 21]);
        Assert::assertSame('Alice2', $row['name']);
        Assert::assertSame(21, $row['age']);
        Assert::assertSame(1, FocUser::count());
        $fresh = FocUser::find($row['id']);
        Assert::assertSame('Alice2', $fresh['name']);
    }

    public function testUpdateOrCreateCreatesMissing(): void
    {
        $row = FocUser::updateOrCreate(['email' => 'e@f.com'], ['name' => 'Eve', 'age' => 25]);
        Assert::assertSame('Eve', $row['name']);
        Assert::assertSame(25, $row['age']);
        Assert::assertSame(2, FocUser::count());
    }

    public function testFirstOrNewFindsExisting(): void
    {
        $row = FocUser::firstOrNew(['email' => 'a@b.com'], ['name' => 'X']);
        Assert::assertSame('Alice', $row['name']);
        Assert::assertSame(1, FocUser::count());
    }

    public function testFirstOrNewReturnsUnsavedWhenMissing(): void
    {
        $row = FocUser::firstOrNew(['email' => 'g@h.com'], ['name' => 'Grace', 'age' => 40]);
        Assert::assertSame('g@h.com', $row['email']);
        Assert::assertSame('Grace', $row['name']);
        Assert::assertSame(40, $row['age']);
        // 不应落库
        Assert::assertSame(1, FocUser::count());
    }
}
