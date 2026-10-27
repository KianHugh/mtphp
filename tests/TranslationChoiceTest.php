<?php
declare(strict_types=1);

/**
 * 本地化复数 choice 测试（{n} / {a,b} / {a,*} / {*,b} / {*} 选择语法）
 * 运行：php8 tests/run.php TranslationChoiceTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Translation\Translator;

class TranslationChoiceTest extends TestCase
{
    public function testExactCountSegment(): void
    {
        $t = new Translator();
        $t->addLines([
            'apples' => '{0} 没有苹果|{1} 一个苹果|[2,*] :count 个苹果',
        ], 'zh-CN');

        Assert::assertSame('没有苹果', $t->choice('apples', 0));
        Assert::assertSame('一个苹果', $t->choice('apples', 1));
        Assert::assertSame('5 个苹果', $t->choice('apples', 5));
    }

    public function testRangeSegment(): void
    {
        $t = new Translator();
        $t->addLines([
            'tasks' => '{0} 无|[1,3] 少量 :count 个|[4,*] 大量 :count 个',
        ], 'zh-CN');

        Assert::assertSame('无', $t->choice('tasks', 0));
        Assert::assertSame('少量 2 个', $t->choice('tasks', 2));
        Assert::assertSame('大量 10 个', $t->choice('tasks', 10));
        Assert::assertSame('少量 3 个', $t->choice('tasks', 3)); // 边界 3 属 [1,3]
    }

    public function testUpperBoundSegment(): void
    {
        $t = new Translator();
        $t->addLines([
            'level' => '{*,10} 低（≤10）|{*,*} 高（>10）',
        ], 'zh-CN');

        Assert::assertSame('低（≤10）', $t->choice('level', 5));
        Assert::assertSame('低（≤10）', $t->choice('level', 10));
        Assert::assertSame('高（>10）', $t->choice('level', 11));
    }

    public function testCatchAllFallback(): void
    {
        $t = new Translator();
        $t->addLines([
            'msg' => '默认文案（无匹配条件）',
        ], 'zh-CN');
        // 无 {条件} 形态：恰两段按单复数降级；此处单段直接返回
        Assert::assertSame('默认文案（无匹配条件）', $t->choice('msg', 3));
    }

    public function testSimpleSingularPlural(): void
         {
        $t = new Translator();
        $t->addLines(['doc' => '一个文档|:count 个文档'], 'zh-CN');
        Assert::assertSame('一个文档', $t->choice('doc', 1));
        Assert::assertSame('3 个文档', $t->choice('doc', 3));
    }

    public function testCountPlaceholderAutoReplaced(): void
    {
        $t = new Translator();
        $t->addLines(['items' => '[2,*] 共 :count 件宝贝'], 'zh-CN');
        Assert::assertSame('共 7 件宝贝', $t->choice('items', 7));
    }
}
