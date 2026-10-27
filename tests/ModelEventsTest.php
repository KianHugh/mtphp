<?php
declare(strict_types=1);

/**
 * 模型事件（creating/created/updating/updated/saving/saved/deleting/deleted
 * + 软删除 restoring/restored/forceDeleting/forceDeleted）测试（内存 SQLite）
 * 运行：php8 tests/run.php ModelEventsTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Database\SoftDeletes;
use Mtphp\Database\Schema\Schema;
use Mtphp\Database\Schema\Blueprint;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

// ---- 基础模型（无软删除）----

class EvtUser extends Model
{
    protected static ?string $table = 'users';
    protected static string $primaryKey = 'id';
}

// ---- 软删除模型 ----

class EvtAccount extends Model
{
    use SoftDeletes;

    protected static ?string $table = 'accounts';
    protected static string $primaryKey = 'id';
}

// ---- 观察者（方法名即事件名）----

class EvtObserver
{
    public array $log = [];

    public function creating(string $class, $payload): void
    {
        $this->log[] = 'creating';
    }

    public function created(string $class, $payload): void
    {
        $this->log[] = 'created';
        // 记录自增 id 供断言
        $this->lastId = $payload['id'] ?? null;
    }

    public function saved(string $class, $payload): void
    {
        $this->log[] = 'saved';
    }
}

class ModelEventsTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('accounts', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->softDeletes();
        });

        EvtUser::clearModelEvents();
        EvtAccount::clearModelEvents();
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
    }

    public function testCreatedEventFiresWithId(): void
    {
        $captured = [];
        EvtUser::registerModelEvent('created', function (string $class, $payload) use (&$captured): void {
            $captured = $payload;
        });

        $id = EvtUser::create(['name' => 'neo']);
        Assert::assertSame(1, $id);
        Assert::assertSame(1, $captured['id'] ?? null);
        Assert::assertSame('neo', $captured['name'] ?? null);
    }

    public function testCreatingCanHaltInsert(): void
    {
        EvtUser::registerModelEvent('creating', function (string $class, $payload) {
            return false; // 拦截
        });

        $id = EvtUser::create(['name' => 'blocked']);
        Assert::assertSame(0, $id, 'creating 返回 false 应阻止插入');
        Assert::assertSame(0, EvtUser::count(), '不应有行被写入');
    }

    public function testObserveBindsMultipleEvents(): void
    {
        $observer = new EvtObserver();
        EvtUser::observe($observer);

        $id = EvtUser::create(['name' => 'obs']);
        Assert::assertSame(1, $id);
        Assert::assertTrue(in_array('creating', $observer->log, true));
        Assert::assertTrue(in_array('created', $observer->log, true));
        Assert::assertTrue(in_array('saved', $observer->log, true));
        Assert::assertSame(1, $observer->lastId ?? null);
    }

    public function testUpdatedEventAndHalt(): void
    {
        $id = EvtUser::create(['name' => 'a']);
        Assert::assertSame(1, $id);

        $updated = [];
        EvtUser::registerModelEvent('updated', function (string $class, $payload) use (&$updated): void {
            $updated = $payload;
        });
        EvtUser::update($id, ['name' => 'b']);
        Assert::assertSame('b', $updated['attributes']['name'] ?? null);

        // 拦截 updating
        EvtUser::clearModelEvents();
        EvtUser::registerModelEvent('updating', function () {
            return false;
        });
        $aff = EvtUser::update($id, ['name' => 'c']);
        Assert::assertSame(0, $aff, 'updating 返回 false 应跳过更新');
        $row = EvtUser::find($id);
        Assert::assertSame('b', $row['name'], '名字应保持不变');
    }

    public function testDeletedEventAndHalt(): void
    {
        $id = EvtUser::create(['name' => 'x']);
        $deleted = [];
        EvtUser::registerModelEvent('deleted', function (string $class, $payload) use (&$deleted): void {
            $deleted = $payload;
        });
        EvtUser::delete($id);
        Assert::assertSame($id, $deleted['id'] ?? null);
        Assert::assertSame(0, EvtUser::count());

        // 拦截 deleting
        EvtUser::clearModelEvents();
        $id2 = EvtUser::create(['name' => 'y']);
        EvtUser::registerModelEvent('deleting', function () {
            return false;
        });
        $aff = EvtUser::delete($id2);
        Assert::assertSame(0, $aff, 'deleting 返回 false 应跳过删除');
        Assert::assertSame(1, EvtUser::count(), '行应保留');
    }

    public function testEventsAreIsolatedPerModel(): void
    {
        // 给 EvtUser 注册事件，EvtAccount 不应受影响
        EvtUser::registerModelEvent('creating', function () {
            return false;
        });
        // EvtAccount 未注册 deleting 拦截，应能正常软删
        $accId = EvtAccount::create(['name' => 'acc']);
        $aff = EvtAccount::delete($accId);
        Assert::assertSame(1, $aff, 'EvtAccount 不应受 EvtUser 事件影响');
    }

    public function testSoftDeletesEvents(): void
    {
        $log = [];
        foreach (['deleting', 'deleted', 'restoring', 'restored', 'forceDeleting', 'forceDeleted'] as $ev) {
            EvtAccount::registerModelEvent($ev, function (string $class, $payload) use (&$log, $ev): void {
                $log[] = $ev;
            });
        }

        $accId = EvtAccount::create(['name' => 'acc']);
        EvtAccount::delete($accId);
        Assert::assertTrue(in_array('deleting', $log, true));
        Assert::assertTrue(in_array('deleted', $log, true));

        // 默认查询应隐藏软删记录
        Assert::assertSame(0, EvtAccount::count(), '软删后默认查询应不含该记录');

        EvtAccount::restore($accId);
        Assert::assertTrue(in_array('restoring', $log, true));
        Assert::assertTrue(in_array('restored', $log, true));
        Assert::assertSame(1, EvtAccount::count(), '恢复后应可见');

        EvtAccount::forceDelete($accId);
        Assert::assertTrue(in_array('forceDeleting', $log, true));
        Assert::assertTrue(in_array('forceDeleted', $log, true));
        Assert::assertSame(0, EvtAccount::count(), '物理删除后不可见');
    }
}
