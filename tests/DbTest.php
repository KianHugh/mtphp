<?php
declare(strict_types=1);

/**
 * 数据库层测试（Query / Connector / 事务 链路）
 * 基于内存 SQLite，与 MySQL 无关，用于回归保护。
 *
 * 运行：php8 tests/run.php DbTest   或   php8 tests/DbTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Database\Query;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Tests\SqliteConnector;

class DbTest extends TestCase
{
    private \PDO $pdo;
    private Query $q;

    /**
     * 每个用例前新建一张干净的内存表 + Query 实例
     */
    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE user (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)');
        $this->q = new Query(new SqliteConnector($this->pdo), '');
    }

    /**
     * 插入 + 查询 + where + first
     */
    public function testInsertAndSelect(): void
    {
        $id = $this->q->table('user')->insert(['name' => '张三', 'age' => 30]);
        Assert::assertSame(1, $id); // 自增首条应为 1

        $this->q->table('user')->insert(['name' => '李四', 'age' => 40]);

        $all = $this->q->table('user')->orderBy('id', 'asc')->get();
        Assert::assertSame(2, count($all));

        $one = $this->q->table('user')->where('name', '张三')->first();
        Assert::assertSame(30, $one['age']);
    }

    /**
     * where 比较 + count + update + value
     */
    public function testWhereCountAndUpdate(): void
    {
        $this->q->table('user')->insert(['name' => '张三', 'age' => 30]);
        $this->q->table('user')->insert(['name' => '李四', 'age' => 40]);

        $cnt = $this->q->table('user')->where('age', '>', 35)->count();
        Assert::assertSame(1, $cnt); // 仅李四(40) 命中

        $upd = $this->q->table('user')->where('name', '张三')->update(['age' => 31]);
        Assert::assertSame(1, $upd); // 影响 1 行
        Assert::assertSame(31, $this->q->table('user')->where('name', '张三')->value('age'));
    }

    /**
     * 事务内抛异常应整体回滚
     */
    public function testTransactionRollback(): void
    {
        $this->q->table('user')->insert(['name' => '张三', 'age' => 30]);
        $this->q->table('user')->insert(['name' => '李四', 'age' => 40]);

        // 内层插入王五后抛错，应整体回滚
        Assert::assertException(function () {
            $this->q->table('user')->transaction(function () {
                $this->q->table('user')->insert(['name' => '王五', 'age' => 50]);
                throw new \RuntimeException('boom');
            });
        });

        Assert::assertSame(2, $this->q->table('user')->count()); // 王五未插入
    }
}
