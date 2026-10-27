<?php
declare(strict_types=1);

/**
 * 测试用例基类（零依赖，框架自带）
 *
 * 编写测试：新建 tests/XxxTest.php，定义 class XxxTest extends TestCase，
 * 把测试方法命名为 test*（如 testUserCrud），在方法里用 Assert::* 断言。
 *
 * 生命周期（由 tests/run.php 驱动）：
 *   setUp()    → 每个 test* 方法执行前调用（放初始化，如建内存库、清缓存）
 *   test*()    → 业务逻辑 + 断言
 *   tearDown() → 每个 test* 方法执行后调用（放清理，如删临时文件）
 *
 * 子类按需重写 setUp / tearDown 即可，无需其它配置。
 */
namespace Mtphp\Testing;

abstract class TestCase
{
    /**
     * 每个测试方法执行前的准备钩子，子类按需重写。
     */
    protected function setUp(): void
    {
    }

    /**
     * 每个测试方法执行后的清理钩子，子类按需重写。
     */
    protected function tearDown(): void
    {
    }

    /**
     * 由运行器调用：在本类作用域内依次执行 setUp → test* → tearDown，
     * 这样 protected 的 setUp/tearDown 才能从类内部访问（从全局作用域无法直接调用）。
     * 若 test* 抛异常，tearDown 仍会在 finally 中执行；异常会向上抛出供运行器捕获。
     */
    public function runBare(string $method): void
    {
        $this->setUp();
        try {
            $this->{$method}();
        } finally {
            $this->tearDown();
        }
    }
}
