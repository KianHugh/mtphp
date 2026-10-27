<?php
declare(strict_types=1);

use Mtphp\Database\Connector;
use Mtphp\Database\ReadWriteConnection;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;

require_once __DIR__ . '/bootstrap.php';

/**
 * 请求级 sticky 测试：用记录型假 Connector，验证“写后读主、复位后读从”
 */
class StickyFakeConn implements Connector
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

class StickyTest extends TestCase
{
    public function testReadGoesToReplicaByDefault(): void
    {
        $write = new StickyFakeConn();
        $read  = new StickyFakeConn();
        $rw    = new ReadWriteConnection($write, [$read]);

        $rw->select('SELECT 1');
        Assert::assertSame(1, count($read->log), '默认读应走从库');
        Assert::assertSame(0, count($write->log), '默认读不应走主库');
    }

    public function testWriteMakesSubsequentReadsStickyToMaster(): void
    {
        $write = new StickyFakeConn();
        $read  = new StickyFakeConn();
        $rw    = new ReadWriteConnection($write, [$read]);

        $rw->insert('INSERT INTO t VALUES (?)', [1]); // 写 → 触发 sticky
        $rw->select('SELECT 1');                       // 写后读 → 应走主

        Assert::assertSame(2, count($write->log), '写与写后读都应走主'); // insert + select
        Assert::assertSame(0, count($read->log), 'sticky 期间读不应落到从库');
    }

    public function testResetStickyRestoresReplicaReads(): void
    {
        $write = new StickyFakeConn();
        $read  = new StickyFakeConn();
        $rw    = new ReadWriteConnection($write, [$read]);

        $rw->update('UPDATE t SET a=1');
        $rw->resetSticky();
        $rw->select('SELECT 1');

        Assert::assertSame(1, count($read->log), '复位后读应回落从库');
        Assert::assertSame(1, count($write->log), '主库仅记录先前的写');
    }

    public function testStickyDoesNotOverrideTransactionMasterRead(): void
    {
        $write = new StickyFakeConn();
        $read  = new StickyFakeConn();
        $rw    = new ReadWriteConnection($write, [$read]);
        $write->inTx = true; // 事务内

        $rw->select('SELECT 1'); // 即使未写，事务内读也强制主
        Assert::assertSame(1, count($write->log));
        Assert::assertSame(0, count($read->log));
    }

    public function testSetStickyForcesMasterRead(): void
    {
        $write = new StickyFakeConn();
        $read  = new StickyFakeConn();
        $rw    = new ReadWriteConnection($write, [$read]);
        $rw->setSticky(true);

        $rw->select('SELECT 1');
        Assert::assertSame(0, count($read->log), '手动 sticky 后读应走主');
    }
}
