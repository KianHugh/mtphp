<?php
declare(strict_types=1);

/**
 * 路由增强测试（命名路由 / route() / resource() / 分组 as）
 * 运行：php tests/run.php RouteEnhanceTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Config;
use Mtphp\Router;

class RouteEnhanceTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router(new Config('/dev/null'), '/tmp');
    }

    public function testNamedRouteAndRouteHelper(): void
    {
        $this->router->get('/users/{id}', 'User@show', [], 'users.show');
        $url = $this->router->route('users.show', ['id' => 5]);
        Assert::assertSame('/users/5', $url);
    }

    public function testRouteLeftoverParamsAsQuery(): void
    {
        $this->router->get('/users', 'User@index', [], 'users.index');
        $url = $this->router->route('users.index', ['page' => 2, 'sort' => 'name']);
        Assert::assertContains($url, '/users?page=2');
        Assert::assertContains($url, 'sort=name');
    }

    public function testResourceRegistersSevenRoutes(): void
    {
        $this->router->resource('posts', 'Post@');

        $expect = [
            ['GET', '/posts', 'posts.index'],
            ['GET', '/posts/create', 'posts.create'],
            ['POST', '/posts', 'posts.store'],
            ['GET', '/posts/{id}', 'posts.show'],
            ['GET', '/posts/{id}/edit', 'posts.edit'],
            ['PUT', '/posts/{id}', 'posts.update'],
            ['DELETE', '/posts/{id}', 'posts.destroy'],
        ];
        foreach ($expect as [$method, $uri, $name]) {
            $match = $this->router->dispatch($method, $uri);
            Assert::assertNotNull($match, "应匹配 {$method} {$uri}");
        }
        // 命名路由 URL 代入
        Assert::assertSame('/posts/9', $this->router->route('posts.show', ['id' => 9]));
        Assert::assertSame('/posts/9/edit', $this->router->route('posts.edit', ['id' => 9]));
        Assert::assertSame('/posts', $this->router->route('posts.index'));
    }

    public function testGroupAsPrefix(): void
    {
        $this->router->group(['prefix' => '/api', 'as' => 'api.'], function (Router $r): void {
            $r->get('/ping', 'Ping@index', [], 'ping');
        });
        Assert::assertSame('/api/ping', $this->router->route('api.ping'));
    }

    public function testRouteThrowsOnMissingName(): void
    {
        Assert::assertException(fn () => $this->router->route('nope'), \RuntimeException::class);
    }
}
