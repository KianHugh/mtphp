<?php
declare(strict_types=1);

use Mtphp\Console\Application;
use Mtphp\Console\Commands\MakeMigration;
use Mtphp\Console\Commands\Migrate;
use Mtphp\Console\Commands\MakeModel;
use Mtphp\Console\Commands\MakeController;
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
 * 脚手架选项联动：
 *   · make:controller --resource 生成 7 方法 RESTful 控制器（与 Router::resource 约定的动作名一致）
 *   · make:model --migration / -m 生成模型的同时联动生成建表迁移
 * 用临时工程根 + 内存 SQLite 验证，全程不污染仓库。
 */
class ScaffoldOptionsTest extends TestCase
{
    private string $root;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mtphp_scopt_' . uniqid('', true);
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
        $items = scandir($dir) ?: [];
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
        return $app;
    }

    public function testResourceControllerGeneratesSevenActions(): void
    {
        $console = $this->console();
        Assert::assertSame(0, $console->run(['mtphp', 'make:controller', 'Comment', '--resource']));

        $file = $this->root . '/app/Controllers/CommentController.php';
        Assert::assertTrue(is_file($file), 'make:controller --resource 应生成控制器文件');
        require_once $file;

        $ctrl = new \App\Controllers\CommentController();
        $req  = Request::capture();

        // 每个 RESTful 动作都返回 Response，且动作名与 Router::resource 约定一致
        foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'] as $action) {
            Assert::assertTrue(method_exists($ctrl, $action), "资源控制器应含 {$action} 方法");
        }

        $show = $ctrl->show($req, '7');
        Assert::assertTrue($show instanceof Response);
        Assert::assertSame(200, $show->getStatus());
        Assert::assertSame(7, (int) (json_decode((string) $show->getBody(), true)['data']['id'] ?? null));

        $store = $ctrl->store($req);
        Assert::assertSame(201, $store->getStatus(), 'store 应返回 201');

        $destroy = $ctrl->destroy($req, '9');
        Assert::assertSame(204, $destroy->getStatus(), 'destroy 应返回 204');
    }

    public function testModelWithMigrationGeneratesTable(): void
    {
        $console = $this->console();
        Assert::assertSame(0, $console->run(['mtphp', 'make:model', 'Post', '--migration']));

        $modelFile = $this->root . '/app/Models/Post.php';
        Assert::assertTrue(is_file($modelFile), 'make:model 应生成模型文件');

        // 联动迁移：database/migrations 下应多出一个以 create_post_table 结尾的迁移文件
        $migrations = glob($this->root . '/database/migrations/*_create_post_table.php') ?: [];
        Assert::assertSame(1, count($migrations), 'make:model --migration 应联动生成建表迁移');

        // 跑迁移建表（模型表名按类名 snake_case 推导为 post）
        Assert::assertSame(0, $console->run(['mtphp', 'migrate']));
        Assert::assertTrue(Schema::hasTable('post'), 'post 表应已由联动迁移创建');

        // 模型可用：插入 + 查回
        require_once $modelFile;
        $ts = date('Y-m-d H:i:s');
        $id = \App\Models\Post::create(['created_at' => $ts, 'updated_at' => $ts]);
        Assert::assertTrue($id > 0, '生成模型 create() 应返回自增主键');
        Assert::assertSame(1, count(\App\Models\Post::all()), '生成模型 all() 应查到刚插入的行');
    }

    public function testControllerWithModelIsUsable(): void
    {
        $console = $this->console();
        // 先生成模型 + 联动迁移，再建表；随后生成模型感知的控制器
        Assert::assertSame(0, $console->run(['mtphp', 'make:model', 'Article', '--migration']));
        Assert::assertSame(0, $console->run(['mtphp', 'migrate']));
        Assert::assertSame(0, $console->run(['mtphp', 'make:controller', 'Article', '--model=Article']));

        $ctrlFile = $this->root . '/app/Controllers/ArticleController.php';
        Assert::assertTrue(is_file($ctrlFile), 'make:controller --model 应生成控制器文件');
        require_once $this->root . '/app/Models/Article.php';
        require_once $ctrlFile;

        $ctrl = new \App\Controllers\ArticleController();
        // 模拟一次真实 POST（CLI 下 Request::capture() 读 $_POST；模型表仅 id + 时间戳列）
        $_POST = ['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
        $req   = Request::capture();

        // 生成文件应 use 模型并含全部 7 个资源动作
        $src = (string) file_get_contents($ctrlFile);
        Assert::assertTrue(str_contains($src, 'use App\\Models\\Article;'), '控制器应 use 绑定的模型');
        foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'] as $action) {
            Assert::assertTrue(method_exists($ctrl, $action), "模型感知控制器应含 {$action} 方法");
        }

        // index 查全部（初始空）
        $idx = $ctrl->index($req);
        Assert::assertTrue($idx instanceof Response);
        Assert::assertSame([], json_decode((string) $idx->getBody(), true)['data'] ?? null, 'index 应返回空列表');

        // store 插入并回 201
        $store = $ctrl->store($req);
        Assert::assertSame(201, $store->getStatus());
        $newId = (int) (json_decode((string) $store->getBody(), true)['data']['id'] ?? null);
        Assert::assertTrue($newId > 0, 'store 应通过模型写入并返回新 id');

        // show 查回刚插入的行
        $show = $ctrl->show($req, (string) $newId);
        Assert::assertSame(200, $show->getStatus());
        Assert::assertSame($newId, (int) (json_decode((string) $show->getBody(), true)['data']['id'] ?? null));

        // update 改后回 200
        $upd = $ctrl->update($req, (string) $newId);
        Assert::assertSame(200, $upd->getStatus());

        // destroy 删后回 204，再 show 应为 404
        $del = $ctrl->destroy($req, (string) $newId);
        Assert::assertSame(204, $del->getStatus());
        $gone = $ctrl->show($req, (string) $newId);
        Assert::assertSame(404, $gone->getStatus(), '删除后再查应返回 404');

        $_POST = []; // 复位 superglobal，避免泄漏到其它用例
    }
}
