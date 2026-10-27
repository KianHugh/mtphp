<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Console\Commands;

use Mtphp\Console\Command;

/**
 * make:controller —— 生成控制器骨架
 *
 *   php bin/mtphp make:controller User
 *   php bin/mtphp make:controller Api/UserController   # 生成到 app/Controllers/Api/UserController.php
 *   php bin/mtphp make:controller User --resource       # 生成 7 方法 RESTful 资源控制器
 *   php bin/mtphp make:controller Post --model=Post      # 生成直接调用 Post 模型的资源控制器
 */
class MakeController extends Command
{
    public function configure(): void
    {
        $this->name        = 'make:controller';
        $this->description = '生成控制器骨架（写入 app/Controllers），--resource / --model 生成模型感知的 RESTful 全套';
    }

    public function handle(): int
    {
        $name = (string) $this->argument(0);
        if ($name === '') {
            $this->error('用法：php bin/mtphp make:controller <ControllerName> [--resource] [--model=ModelName]');
            return 1;
        }

        $rel   = ltrim(str_replace('\\', '/', $name), '/');
        $parts = explode('/', $rel);
        $base  = array_pop($parts);
        if (!str_ends_with($base, 'Controller')) {
            $base .= 'Controller';
        }
        $class = $base;
        $sub   = $parts !== [] ? implode('/', $parts) . '/' : '';
        $ns    = 'App\\Controllers' . ($parts !== [] ? '\\' . str_replace('/', '\\', implode('\\', $parts)) : '');

        $dir  = $this->getRoot() . '/app/Controllers/' . $sub;
        $file = $dir . $base . '.php';
        if (is_file($file)) {
            $this->warn("已存在，跳过：{$file}");
            return 0;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $model    = (string) $this->option('model', '');
        $resource = (bool) $this->option('resource', false);

        if ($model !== '') {
            $stub  = $this->modelStub($ns, $class, $model);
            $suffix = '（模型感知资源控制器，已绑定 ' . $model . '）';
        } elseif ($resource) {
            $stub  = $this->resourceStub($ns, $class);
            $suffix = '（RESTful 资源控制器）';
        } else {
            $stub  = $this->plainStub($ns, $class);
            $suffix = '';
        }

        file_put_contents($file, $stub);
        $this->info("已生成：{$file}{$suffix}");
        return 0;
    }

    /**
     * 单方法骨架（默认）
     */
    protected function plainStub(string $ns, string $class): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace {$ns};

use Mtphp\Http\Request;

/**
 * {$class}（由 make:controller 生成）
 */
class {$class}
{
    public function index(Request \$request)
    {
        return response()->json(['ok' => true]);
    }
}
PHP;
    }

    /**
     * 7 方法 RESTful 资源控制器（与 Router::resource 约定的动作名一致：
     * index/create/store/show/edit/update/destroy）
     */
    protected function resourceStub(string $ns, string $class): string
    {
        return <<<PHP
<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace {$ns};

use Mtphp\Http\Request;

/**
 * {$class}（由 make:controller --resource 生成，RESTful 资源控制器）
 *
 * 配合 Route::resource('{name}', __CLASS__) 使用：
 *   GET /{name}          @index
 *   GET /{name}/create   @create
 *   POST /{name}         @store
 *   GET /{name}/{id}     @show
 *   GET /{name}/{id}/edit @edit
 *   PUT /{name}/{id}     @update
 *   DELETE /{name}/{id}  @destroy
 */
class {$class}
{
    // GET /resource
    public function index(Request \$request)
    {
        return response()->json(['data' => []]);
    }

    // GET /resource/create
    public function create(Request \$request)
    {
        return response()->json(['data' => null]);
    }

    // POST /resource
    public function store(Request \$request)
    {
        return response()->json(['data' => null], 201);
    }

    // GET /resource/{id}
    public function show(Request \$request, \$id)
    {
        return response()->json(['data' => ['id' => \$id]]);
    }

    // GET /resource/{id}/edit
    public function edit(Request \$request, \$id)
    {
        return response()->json(['data' => ['id' => \$id]]);
    }

    // PUT/PATCH /resource/{id}
    public function update(Request \$request, \$id)
    {
        return response()->json(['data' => ['id' => \$id]]);
    }

    // DELETE /resource/{id}
    public function destroy(Request \$request, \$id)
    {
        return response()->json(['data' => ['id' => \$id]], 204);
    }
}
PHP;
    }

    /**
     * 模型感知的 RESTful 资源控制器（--model=ModelName）
     *
     * 方法与 Router::resource 约定一致，且直接调用给定模型（index/show/create/update/delete），
     * 落地即可接 Route::resource 使用，无需再手写查询。
     * $model 可传短名（Post → App\Models\Post）或完整类名（含反斜杠则原样引用）。
     */
    protected function modelStub(string $ns, string $class, string $model): string
    {
        $fqcn  = str_contains($model, '\\') ? $model : 'App\\Models\\' . $model;
        $short = basename(str_replace('\\', '/', $fqcn));
        return <<<PHP
<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace {$ns};

use Mtphp\Http\Request;
use {$fqcn};

/**
 * {$class}（由 make:controller --model={$short} 生成，模型感知的 RESTful 资源控制器）
 *
 * 配合 Route::resource('{name}', __CLASS__) 使用；方法已直接调用 {$short} 模型。
 */
class {$class}
{
    // GET /resource
    public function index(Request \$request)
    {
        return response()->json(['data' => {$short}::all()]);
    }

    // GET /resource/create
    public function create(Request \$request)
    {
        return response()->json(['data' => null]);
    }

    // POST /resource
    public function store(Request \$request)
    {
        \$id = {$short}::create(\$request->all());
        return response()->json(['data' => ['id' => \$id]], 201);
    }

    // GET /resource/{id}
    public function show(Request \$request, \$id)
    {
        \$item = {$short}::find(\$id);
        if (\$item === null) {
            return response()->json(['error' => 'not found'], 404);
        }
        return response()->json(['data' => \$item]);
    }

    // GET /resource/{id}/edit
    public function edit(Request \$request, \$id)
    {
        \$item = {$short}::find(\$id);
        if (\$item === null) {
            return response()->json(['error' => 'not found'], 404);
        }
        return response()->json(['data' => \$item]);
    }

    // PUT/PATCH /resource/{id}
    public function update(Request \$request, \$id)
    {
        \$item = {$short}::find(\$id);
        if (\$item === null) {
            return response()->json(['error' => 'not found'], 404);
        }
        {$short}::update(\$id, \$request->all());
        return response()->json(['data' => ['id' => \$id]]);
    }

    // DELETE /resource/{id}
    public function destroy(Request \$request, \$id)
    {
        {$short}::delete(\$id);
        return response()->json(['data' => ['id' => \$id]], 204);
    }
}
PHP;
    }
}
