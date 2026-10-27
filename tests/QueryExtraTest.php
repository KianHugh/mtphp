<?php
declare(strict_types=1);

use Mtphp\Database\DB;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Tests\SqliteConnector;

require_once __DIR__ . '/bootstrap.php';

/**
 * Query 增强测试：chunk / whereDate / 嵌套 orWhere 分组（基于内存 SQLite）
 */
class QueryExtraTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE item (id INTEGER PRIMARY KEY, cat TEXT, created_at TEXT)');
        DB::setResolver(static fn () => new SqliteConnector($pdo));

        for ($i = 1; $i <= 7; $i++) {
            DB::table('item')->insert([
                'cat'        => $i % 2 === 0 ? 'even' : 'odd',
                'created_at' => '2026-10-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function testWhereDateFiltersByDay(): void
    {
        $rows = DB::table('item')->whereDate('created_at', '2026-10-03')->get();
        Assert::assertSame(1, count($rows));
        Assert::assertSame('2026-10-03', $rows[0]['created_at']);
    }

    public function testWhereDateCompiles(): void
    {
        $sql = DB::table('item')->whereDate('created_at', '>=', '2026-10-01')->toSql();
        Assert::assertContains($sql, 'DATE(`created_at`) >= ?');
    }

    public function testNestedOrWhereGroup(): void
    {
        $sql = DB::table('item')
            ->where('cat', 'even')
            ->whereGroup(static function ($q): void {
                $q->where('id', 2)->orWhere('id', 4);
            })
            ->toSql();
        Assert::assertContains($sql, '`cat` = ?');
        Assert::assertContains($sql, '(`id` = ? OR `id` = ?)');
    }

    public function testNestedGroupReadsCorrectly(): void
    {
        $rows = DB::table('item')
            ->where('cat', 'even')
            ->whereGroup(static function ($q): void {
                $q->where('id', 2)->orWhere('id', 4);
            })
            ->orderBy('id')
            ->get();
        Assert::assertSame(2, count($rows));
        Assert::assertSame(2, $rows[0]['id']);
        Assert::assertSame(4, $rows[1]['id']);
    }

    public function testChunkProcessesAllRows(): void
    {
        $seen = [];
        $batches = 0;
        DB::table('item')->orderBy('id')->chunk(2, static function (array $rows) use (&$seen, &$batches): void {
            $batches++;
            foreach ($rows as $r) {
                $seen[] = $r['id'];
            }
        });
        Assert::assertSame([1, 2, 3, 4, 5, 6, 7], $seen);
        Assert::assertSame(4, $batches); // 7 行 / 2 = 4 批（最后一批 1 行）
    }

    public function testChunkEarlyStop(): void
    {
        $seen = [];
        DB::table('item')->orderBy('id')->chunk(3, static function (array $rows) use (&$seen): bool {
            foreach ($rows as $r) {
                $seen[] = $r['id'];
            }
            return count($seen) < 4 ? true : false; // 本批处理完后再判断；小于 4 继续，否则停
        });
        // chunk 以批为单位处理：第 1 批 [1,2,3]（继续），第 2 批 [4,5,6]（停止），故见到 6 行、不含 7
        Assert::assertSame([1, 2, 3, 4, 5, 6], $seen);
        Assert::assertFalse(in_array(7, $seen, true));
    }
}
