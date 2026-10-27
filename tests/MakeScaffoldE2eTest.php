<?php
declare(strict_types=1);

use Mtphp\Console\Application;
use Mtphp\Console\Commands\MakeMigration;
use Mtphp\Console\Commands\Migrate;
use Mtphp\Console\Commands\MakeModel;
use Mtphp\Console\Commands\MakeController;
use Mtphp\Console\Commands\MakeMiddleware;
use Mtphp\Database\Connector;
use Mtphp\Database\DB;
use Mtphp\Database\Schema\Schema;
use Mtphp\Http\Request;
use Mtphp\Http\Response;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Tests\SqliteConnector;

require_once __DIR__ . '/bootstrap.php';

/**
 * 脚手架「生成即可用」闭环：用临时工程根 + 内存 SQLite 解析器，
 * 经控制台依次生成 migration / model / controller / middleware，再 migrate 建表，
 * 最后直接实例化生成的类并调用，验证“生成代码真实可运行”——
 *   · 模型能插入并查回数据（create / all）
 *   · 控制器方法能返回 Response（response()->json(...)）
 *   · 中间件能经管道返回内部响应（handle(Request, callable): Response）
 * 全程不污染仓库（tearDown 递归清理临时目录、DB::flush 复位连接与解析器）。
 */
class MakeScaffoldE2eTest extends TestCase
{
    private string $root;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mtphp_scaffold_' . uniqid('', true);
        foreach (['database/migrations', 'app/Models', 'app/Controllers', 'app/Middleware'] as $d) {
            mkdir($this->root . '/' . $d, 0777, true);
        }
        $this->pdo = new \PDO('sqlite::memory:');
        $pdo       = $this->pdo;
        DB::setResolver(static fn (): Connector => new SqliteConnector($pdo));
    }

    protected function tearDown(): void
    {
        DB::flush();
        $this->rmrf($this->root);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') {
                continue;
            }
            $p = $dir . '/' . $it;
            if (is_dir($p)) {
                $this->rmrf($p);
            } else {
                unlink($p);
            }
        }
        rmdir($dir);
    }

    private function console(): Application
    {
        $app = new Application($this->root);
        $app->add(new MakeMigration());
        $app->add(new Migrate());
        $app->add(new MakeModel());
        $app->add(new MakeController());
        $app->add(new MakeMiddleware());
        return $app;
    }

    public function testScaffoldArtifactsAreUsable(): void
    {
        $console = $this->console();

        // 1) 依次生成四类脚手架（迁移 + 模型 + 控制器 + 中间件）
        Assert::assertSame(0, $console->run(['mtphp', 'make:migration', 'CreateScaffoldDemoTable', '--create=scaffold_demo']));
        Assert::assertSame(0, $console->run(['mtphp', 'make:model', 'ScaffoldDemo']));
        Assert::assertSame(0, $console->run(['mtphp', 'make:controller', 'ScaffoldDemo']));
        Assert::assertSame(0, $console->run(['mtphp', 'make:middleware', 'ScaffoldTrackVisit']));

        // 2) 跑迁移建表
        Assert::assertSame(0, $console->run(['mtphp', 'migrate']));
        Assert::assertTrue(Schema::hasTable('scaffold_demo'), 'scaffold_demo 表应已创建');

        // 3) 生成的「模型」可用：插入 + 查回
        $modelFile = $this->root . '/app/Models/ScaffoldDemo.php';
        Assert::assertTrue(is_file($modelFile), 'make:model 应生成模型文件');
        require_once $modelFile;
        $ts = date('Y-m-d H:i:s');
        $id = \App\Models\ScaffoldDemo::create(['created_at' => $ts, 'updated_at' => $ts]);
        Assert::assertTrue($id > 0, '生成模型 create() 应返回自增主键');
        $all = \App\Models\ScaffoldDemo::all();
        Assert::assertSame(1, count($all), '生成模型 all() 应查到刚插入的行');

        // 4) 生成的「控制器」可用：实例化 + 调用返回 Response
        $ctrlFile = $this->root . '/app/Controllers/ScaffoldDemoController.php';
        Assert::assertTrue(is_file($ctrlFile), 'make:controller 应生成控制器文件');
        require_once $ctrlFile;
        $ctrl = new \App\Controllers\ScaffoldDemoController();
        $resp = $ctrl->index(Request::capture());
        Assert::assertTrue($resp instanceof Response, '生成控制器方法应返回 Response');
        $body = json_decode((string) $resp->getBody(), true);
        Assert::assertSame(true, $body['ok'] ?? null, '生成控制器默认 index 应返回 {"ok":true}');

        // 5) 生成的「中间件」可用：经管道返回内部响应
        $mwFile = $this->root . '/app/Middleware/ScaffoldTrackVisitMiddleware.php';
        Assert::assertTrue(is_file($mwFile), 'make:middleware 应生成中间件文件');
        require_once $mwFile;
        $mw    = new \App\Middleware\ScaffoldTrackVisitMiddleware();
        $inner = new Response('inner');
        $out   = $mw->handle(Request::capture(), static fn (Request $r): Response => $inner);
        Assert::assertTrue($out instanceof Response, '生成中间件应返回 Response');
        Assert::assertSame('inner', $out->getBody(), '透传型中间件应返回内部响应体');
    }
}
