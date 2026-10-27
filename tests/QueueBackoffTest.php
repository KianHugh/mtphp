<?php
declare(strict_types=1);

/**
 * 队列失败重试退避（指数退避）测试
 * 运行：php tests/run.php QueueBackoffTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Queue\DatabaseQueue;
use Tests\SqliteConnector;

class QueueBackoffTest extends TestCase
{
    private \PDO $pdo;

    private DatabaseQueue $queue;

    private SqliteConnector $conn;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->conn = new SqliteConnector($this->pdo);
        // maxAttempts=3, retryDelay=5
        $this->queue = new DatabaseQueue($this->conn, 'jobs', 'failed_jobs', 3, 5, 90);
    }

    private function resetAvailable(): void
    {
        $this->conn->update('UPDATE `jobs` SET `available_at`=?', [time()]);
    }

    public function testFirstFailureBackoffEqualsRetryDelay(): void
    {
        $this->queue->push(['k' => 'v']);
        $this->queue->worker(static fn () => throw new \RuntimeException('boom'), 'default', ['once' => true, 'sleep' => 0]);

        $row = $this->conn->select('SELECT `attempts`, `available_at` FROM `jobs`');
        Assert::assertSame(1, (int) $row[0]['attempts']);
        Assert::assertGreaterThan(3, (int) $row[0]["available_at"] - time());
        Assert::assertLessThan(8, (int) $row[0]["available_at"] - time());
    }

    public function testSecondFailureBackoffDoubles(): void
    {
        $this->queue->push(['k' => 'v']);
        $this->queue->worker(static fn () => throw new \RuntimeException('boom'), 'default', ['once' => true, 'sleep' => 0]);
        $this->resetAvailable();
        $this->queue->worker(static fn () => throw new \RuntimeException('boom'), 'default', ['once' => true, 'sleep' => 0]);

        $row = $this->conn->select('SELECT `attempts`, `available_at` FROM `jobs`');
        Assert::assertSame(2, (int) $row[0]['attempts']);
        Assert::assertGreaterThan(8, (int) $row[0]["available_at"] - time());
        Assert::assertLessThan(13, (int) $row[0]["available_at"] - time());
    }

    public function testExhaustedAttemptsGoToFailed(): void
    {
        $this->queue->push(['k' => 'v']);
        for ($i = 0; $i < 3; $i++) {
            $this->resetAvailable();
            $this->queue->worker(static fn () => throw new \RuntimeException('boom'), 'default', ['once' => true, 'sleep' => 0]);
        }

        $jobs = $this->conn->select('SELECT COUNT(*) AS _c FROM `jobs`');
        Assert::assertSame(0, (int) $jobs[0]['_c']);
        Assert::assertSame(1, $this->queue->failedJobs('default')[0] ? count($this->queue->failedJobs('default')) : 0);
        Assert::assertSame(1, count($this->queue->failedJobs('default')));
    }

    public function testSuccessfulJobIsDeleted(): void
    {
        $this->queue->push(['k' => 'v']);
        $this->queue->worker(static fn () => null, 'default', ['once' => true, 'sleep' => 0]);
        $jobs = $this->conn->select('SELECT COUNT(*) AS _c FROM `jobs`');
        Assert::assertSame(0, (int) $jobs[0]['_c']);
    }
}
