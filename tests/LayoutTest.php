<?php
declare(strict_types=1);

/**
 * 布局模板测试（@extends / @section / @yield / @parent）
 * 不依赖 MySQL；直接指定视图/缓存目录，无需引导 App。
 *
 * 运行：php8 tests/run.php LayoutTest   或   php8 tests/LayoutTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\View\Template;

class LayoutTest extends TestCase
{
    /**
     * 每个用例前指定视图路径并清理布局编译缓存（保证从源重编译）
     */
    protected function setUp(): void
    {
        $root = dirname(__DIR__);
        Template::setPaths($root . '/app/Views', $root . '/runtime/views');
        foreach (glob($root . '/runtime/views/_layout_*.php') ?: [] as $f) {
            @unlink($f);
        }
    }

    /**
     * page.tpl：继承布局 + @parent 合并（默认侧边栏 + 子模板追加项）
     */
    public function testPageWithParent(): void
    {
        $html = Template::renderRaw('page', ['now' => '2026-10-10 12:00:00']);

        Assert::assertContains($html, 'mtphp 站点');                       // 布局外壳
        Assert::assertContains($html, '武汉麦麦心选网络科技有限公司');        // 布局页脚版权
        Assert::assertContains($html, '布局模板演示页');                    // 子模板 title 区块
        Assert::assertContains($html, '本页通过');                          // 子模板 content 区块
        Assert::assertContains($html, '2026-10-10 12:00:00');              // 传入的 $now 变量
        Assert::assertContains($html, '默认侧边栏');                        // @parent 保留布局默认
        Assert::assertContains($html, '子模板追加菜单项 A');                // @parent 后追加
        Assert::assertContains($html, "console.log('mtphp layout demo page loaded')"); // 子模板 scripts 区块
    }

    /**
     * home.tpl：继承布局 + 未定义区块回退布局默认（sidebar），未定义 scripts 不串页
     */
    public function testHomeWithDefaults(): void
    {
        $html = Template::renderRaw('home', [
            'title'     => 'mtphp 首页',
            'framework' => 'mtphp',
            'features'  => ['路由', '数据库', '模板', '中间件'],
        ]);

        Assert::assertContains($html, 'mtphp 站点');
        Assert::assertContains($html, 'mtphp 首页');
        Assert::assertContains($html, '框架特性');
        Assert::assertContains($html, '<li>路由</li>');     // @foreach 列表渲染
        Assert::assertContains($html, '默认侧边栏');        // 未定义 sidebar → 回退布局默认
        Assert::assertNotContains($html, 'mtphp layout demo page loaded'); // 不应串入 page 的脚本
    }
}
