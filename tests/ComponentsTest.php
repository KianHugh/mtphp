<?php
declare(strict_types=1);

/**
 * 组件层测试（模型 Model / 缓存 Cache / 日志 Logger）
 * 不依赖 MySQL：通过 DB::setResolver 把 DB / Model 切到内存 SQLite。
 *
 * 运行：php8 tests/run.php ComponentsTest   或   php8 tests/ComponentsTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Cache\FileCache;
use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Log\Logger;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use function Tests\sqliteResolver;

class ComponentsTest extends TestCase
{
    /**
     * 每个用例前建内存表，并把 DB / Model 切到 SQLite resolver
     */
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE user (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)');
        // 注入 resolver，模拟 Swoole/测试模式下的连接供给（验证 Connector 解耦）
        DB::setResolver(sqliteResolver($pdo));
    }

    /**
     * 轻量模型 CRUD（create / find / where / update / delete / count）
     */
    public function testModelCrud(): void
    {
        $id = User::create(['name' => '张三', 'age' => 30]);
        Assert::assertSame(1, $id);
        User::create(['name' => '李四', 'age' => 40]);
        Assert::assertSame(2, User::count());

        $one = User::find(1);
        Assert::assertSame('张三', $one['name']);

        $older = User::where('age', '>', 35)->get();
        Assert::assertSame(1, count($older)); // 仅李四(40)

        User::update(1, ['age' => 31]);
        Assert::assertSame(31, User::find(1)['age']);

        User::delete(2);
        Assert::assertSame(1, User::count());
    }

    /**
     * 文件缓存：set / get / has / remember / delete / clear
     */
    public function testCache(): void
    {
        $dir = dirname(__DIR__) . '/runtime/cache_smoke';
        $cache = new FileCache($dir, 3600);

        $cache->set('name', '张三', 600);
        Assert::assertSame('张三', $cache->get('name'));
        Assert::assertTrue($cache->has('name'));

        $computed = $cache->remember('price', 60, static fn () => 99);
        Assert::assertSame(99, $computed);
        Assert::assertSame(99, $cache->get('price'));

        $cache->delete('name');
        Assert::assertFalse($cache->has('name'));

        // 清理测试产物
        $cache->clear();
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /**
     * 文件日志：写入 / 级别 / 上下文序列化
     */
    public function testLogger(): void
    {
        $file = dirname(__DIR__) . '/runtime/logs/smoke.log';
        @unlink($file);

        $log = new Logger($file, 'debug');
        $log->info('组件测试', ['step' => 'logger']);
        $log->error('出错了');

        Assert::assertTrue(is_file($file));
        $content = (string) file_get_contents($file);
        Assert::assertContains($content, '组件测试'); // 上下文被序列化
        Assert::assertContains($content, '出错了');

        @unlink($file);
    }
}

/**
 * 示例模型（仅本测试内定义；正式用法见 app/Models/User.php）
 */
class User extends Model
{
    protected static ?string $table = 'user';
}
