<?php
declare(strict_types=1);

/**
 * 本地化 Translator 测试
 * 运行：php8 tests/run.php TranslationTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Translation\Translator;

class TranslationTest extends TestCase
{
    public function testGetAndPlaceholder(): void
    {
        $t = new Translator();
        $t->addLines(['welcome' => '欢迎，:name'], 'zh-CN');
        Assert::assertSame('欢迎，张三', $t->get('welcome', ['name' => '张三']));
    }

    public function testFallbackLocale(): void
    {
        $t = new Translator();
        $t->addLines(['only_zh' => '中文文案'], 'zh-CN');
        $t->setLocale('en');
        $t->setFallback('zh-CN');
        // en 下没有该 key，应回退到 zh-CN
        Assert::assertSame('中文文案', $t->get('only_zh'));
    }

    public function testMissingKeyReturnsKey(): void
    {
        $t = new Translator();
        Assert::assertSame('no.such.key', $t->get('no.such.key'));
    }

    public function testNestedLinesFlattened(): void
    {
        $t = new Translator();
        $t->addLines(['user' => ['profile' => '个人资料']], 'zh-CN');
        Assert::assertSame('个人资料', $t->get('user.profile'));
    }
}
