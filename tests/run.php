<?php
declare(strict_types=1);

/**
 * mtphp 测试运行器（零依赖，框架自带）
 *
 * 自动发现 tests/*Test.php，反射其中所有 public 的 test* 方法，逐个执行，
 * 汇总“用例数 / 通过 / 失败 / 断言数”，并以正确退出码返回（CI 友好）。
 *
 * 用法：
 *   php8 tests/run.php                 # 跑全部测试
 *   php8 tests/run.php DbTest          # 只跑某个类（可多传，空格分隔）
 *   php8 tests/run.php ComponentsTest LayoutTest
 *
 * 退出码：全部通过 0；有失败 1；用法错误 2。
 */

$root = dirname(__DIR__);
require_once $root . '/tests/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;

// 1) 载入所有测试类文件
$files = glob($root . '/tests/*Test.php') ?: [];
if ($files === []) {
    fwrite(STDERR, "未找到任何 tests/*Test.php\n");
    exit(2);
}
foreach ($files as $f) {
    require_once $f;
}

// 2) 收集继承 TestCase 的类
$classes = [];
foreach (get_declared_classes() as $c) {
    if ($c === TestCase::class) {
        continue;
    }
    if (is_subclass_of($c, TestCase::class)) {
        $classes[] = $c;
    }
}

// 命令行可指定只跑某些类（按类名精确匹配）
$only = array_slice($argv, 1);
if ($only !== []) {
    $classes = array_values(array_filter(
        $classes,
        static fn (string $c): bool => in_array($c, $only, true)
    ));
    if ($classes === []) {
        fwrite(STDERR, "未匹配到指定测试类：" . implode(' ', $only) . "\n");
        exit(2);
    }
}

// 3) 逐个用例执行
$total = 0;
$passed = 0;
$failed = 0;
$assertions = 0;
$errors = [];

foreach ($classes as $cls) {
    $ref = new ReflectionClass($cls);

    // 找出本类自身声明、公开、且以 test 开头的方法
    $methods = [];
    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->class === $cls && str_starts_with($m->getName(), 'test')) {
            $methods[] = $m->getName();
        }
    }

    foreach ($methods as $method) {
        $total++;
        $inst = $ref->newInstance();
        Assert::reset(); // 重置断言计数，便于按用例统计
        try {
            $inst->runBare($method); // 内部依次执行 setUp → test* → tearDown
            $assertions += Assert::count();
            $passed++;
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = "✗ {$cls}::{$method}\n    " . $e->getMessage()
                . "\n    " . $e->getFile() . ':' . $e->getLine();
        }
    }
}

// 4) 输出报告
echo str_repeat('=', 52) . "\n";
echo "mtphp 测试报告\n";
echo str_repeat('=', 52) . "\n";
foreach ($errors as $err) {
    echo $err . "\n";
}
echo str_repeat('-', 52) . "\n";
echo sprintf(
    "用例：%d    通过：%d    失败：%d    断言：%d\n",
    $total,
    $passed,
    $failed,
    $assertions
);
echo ($failed === 0 ? "✅ ALL PASSED\n" : "❌ 存在失败用例\n");

exit($failed === 0 ? 0 : 1);
