<?php
declare(strict_types=1);

use Mtphp\Database\Connector;
use Mtphp\Database\ReadWriteConnection;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;

require_once __DIR__ . '/bootstrap.php';

/**
 * ReadWriteConnection 路由测试（用记录型假 Connector，无需真实数据库）
 */
class FakeConn implements Connector
{
    public array $log = [];
    public bool $inTx = false;

    public function getDriver(): string { return 'mysql'; }
    public function getPdo(): ?\PDO { return null; }
    public function select(string $sql, array $b = []): array { $this->log[] = ['select', $sql]; return []; }
    public function statement(string $sql, array $b = []): bool { $this->log[] = ['statement', $sql]; return true; }
    public function insert(string $sql, array $b = []): int { $this->log[] = ['insert', $sql]; return 1; }
    public function update(string $sql, array $b = []): int { $this->log[] = ['update', $sql]; return 1; }
    public function delete(string $sql, array $b = []): int { $this->log[] = ['delete', $sql]; return 1; }
    public function inTransaction(): bool { return $this->inTx; }
}

class ReadWriteTest extends TestCase
{
    public function testReadGoesToReadConn(): void
    {
        $write = new FakeConn();
        $read  = new FakeConn();
        $rw    = new ReadWriteConnection($write, [$read]);

        $rw->select('SELECT * FROM t');
        Assert::assertSame(0, count($write->log), '读不应落到写库');
        Assert::assertSame(1, count($read->log));
    }

    public function testWriteGoesToWriteConn(): void
    {
        $write = new FakeConn();
        $read  = new FakeConn();
        $rw    = new ReadWriteConnection($write, [$read]);

        $rw->insert('INSERT INTO t VALUES (?)', [1]);
        $rw->update('UPDATE t SET a=1');
        $rw->delete('DELETE FROM t');
        $rw->statement('CREATE TABLE x (id INT)');

        Assert::assertSame(4, count($write->log));
        Assert::assertSame(0, count($read->log), '写/事务原语不应落到读库');
    }

    public function testReadInsideTransactionGoesToWrite(): void
    {
        $write = new FakeConn();
        $read  = new FakeConn();
        $rw    = new ReadWriteConnection($write, [$read]);
        $write->inTx = true;

        $rw->select('SELECT * FROM t');
        Assert::assertSame(1, count($write->log), '事务内读应走写库');
        Assert::assertSame(0, count($read->log));
    }

    public function testNoReadConfigFallsBackToWrite(): void
    {
        $write = new FakeConn();
        $rw    = new ReadWriteConnection($write, []);

        $rw->select('SELECT * FROM t');
        Assert::assertSame(1, count($write->log), '无读库时读应回落写');
    }
}
