<?php
declare(strict_types=1);

/**
 * 路由模型绑定测试（内存 SQLite，零外部依赖）
 *   · Router 级：bind + dispatch 把路径参数解析为模型实例；缺失 → 抛 ModelNotFoundException（404）
 *   · App 级：端到端验证控制器按参数名收到模型实例
 * 运行：php8 tests/run.php RouteBindingTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Config;
use Mtphp\Database\DB;
use Mtphp\Database\Model;
use Mtphp\Router;
use Mtphp\Database\Schema\Blueprint;
use Mtphp\Database\Schema\Schema;
use Mtphp\Exception\ModelNotFoundException;
use Mtphp\Http\Request;
use Mtphp\App;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use function Tests\sqliteResolver;
use Tests\SqliteConnector;

// ---- 测试用模型 / 控制器（全局命名，避免与 App\Models\* 冲突）----

class BindUser extends Model
{
    protected static ?string $table = 'bind_users';
    protected static string $primaryKey = 'id';
}

class BindUserController
{
    public function show(Request $request, $user)
    {
        return (new \Mtphp\Http\Response())->json($user);
    }
}

class RouteBindingTest extends TestCase
{
    private \PDO $pdo;

    private string $root;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        DB::setResolver(sqliteResolver($this->pdo));

        Schema::create('bind_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        BindUser::create(['name' => 'alice']);
        BindUser::create(['name' => 'bob']);

        // 端到端用例所需的临时工程根（含关闭安全中间件的配置 + 路由定义）
        $this->root = sys_get_temp_dir() . '/mtphp_rb_' . uniqid('', true);
        foreach (['config', 'app/Routes'] as $d) {
            mkdir($this->root . '/' . $d, 0777, true);
        }
        file_put_contents($this->root . '/config/app.php', <<<'PHP'
<?php return [
    'security'  => ['headers' => false, 'csrf' => false, 'cors' => false],
    'middleware' => [],
];
PHP
        );
        file_put_contents($this->root . '/app/Routes/routes.php', <<<'PHP'
<?php
use Mtphp\Route;
Route::bind('user', BindUser::class);
Route::get('/users/{user}', [BindUserController::class, 'show']);
PHP
        );
    }

    protected function tearDown(): void
    {
        DB::setResolver(static fn () => new SqliteConnector(new \PDO('sqlite::memory:')));
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
    }

    private function rmrf(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            $p = $dir . '/' . $it;
            is_dir($p) ? $this->rmrf($p) : unlink($p);
        }
        rmdir($dir);
    }

    private function router(): Router
    {
        return new Router(new Config('/tmp/mtphp_nope'), '/tmp/mtphp_nope');
    }

    public function testModelBindingResolvesToRow(): void
    {
        $r = $this->router();
        $r->bind('user', BindUser::class);
        $r->get('/users/{user}', [BindUser::class, 'find']);

        $match = $r->dispatch('GET', '/users/1');
        Assert::assertNotNull($match);
        Assert::assertSame(1, $match['params']['user']['id']);
        Assert::assertSame('alice', $match['params']['user']['name']);
    }

    public function testModelBindingThrows404WhenMissing(): void
    {
        $r = $this->router();
        $r->bind('user', BindUser::class);
        $r->get('/users/{user}', [BindUser::class, 'find']);

        Assert::assertException(
            static fn () => $r->dispatch('GET', '/users/999'),
            ModelNotFoundException::class
        );
    }

    public function testCallableResolver(): void
    {
        $r = $this->router();
        $r->bind('user', static fn ($id) => BindUser::findOrFail($id));
        $r->get('/users/{user}', [BindUser::class, 'find']);

        $match = $r->dispatch('GET', '/users/2');
        Assert::assertSame('bob', $match['params']['user']['name']);
    }

    public function testUnboundParamStaysRaw(): void
    {
        $r = $this->router();
        $r->get('/users/{id}', [BindUser::class, 'find']);

        $match = $r->dispatch('GET', '/users/1');
        Assert::assertSame('1', $match['params']['id']);
    }

    public function testEndToEndControllerReceivesModel(): void
    {
        $app = new App($this->root);

        $req = $this->makeRequest('GET', '/users/1');
        $resp = $app->handle($req);
        Assert::assertSame(200, $resp->getStatus());
        $body = json_decode((string) $resp->getBody(), true);
        Assert::assertSame(1, $body['id'] ?? null);
        Assert::assertSame('alice', $body['name'] ?? null);

        // 缺失模型 → 抛出 404 级异常（App::run 会映射为 404 响应）
        $miss = $this->makeRequest('GET', '/users/999');
        Assert::assertException(static fn () => $app->handle($miss), ModelNotFoundException::class);
    }

    private function makeRequest(string $method, string $path): Request
    {
        $ref = new \ReflectionClass(Request::class);
        $req = $ref->newInstanceWithoutConstructor();
        foreach (['method' => $method, 'path' => $path, 'uri' => $path] as $k => $v) {
            $prop = $ref->getProperty($k);
            $prop->setAccessible(true);
            $prop->setValue($req, $v);
        }
        return $req;
    }
}
