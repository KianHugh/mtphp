<?php
declare(strict_types=1);

/**
 * Session 会话组件测试
 * 运行：php8 tests/run.php SessionTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Http\Session;

class SessionTest extends TestCase
{
    public function testArrayDriverCrud(): void
    {
        $s = new Session('array');
        $s->set('name', '张三');
        Assert::assertSame('张三', $s->get('name'));
        Assert::assertTrue($s->has('name'));
        $s->forget('name');
        Assert::assertFalse($s->has('name'));
        $s->set('a', 1);
        $s->flush();
        Assert::assertFalse($s->has('a'));
    }

    public function testFileDriverPersists(): void
    {
        $dir = sys_get_temp_dir() . '/mtphp_sess_test_' . uniqid();
        $s1 = new Session('file', $dir);
        $s1->set('k', 'v');
        $s1->save();

        $s2 = new Session('file', $dir);
        $s2->setId($s1->getId());
        $s2->start();
        Assert::assertSame('v', $s2->get('k'));

        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    public function testFlashIsOneTime(): void
    {
        $dir = sys_get_temp_dir() . '/mtphp_flash_test_' . uniqid();
        $s1 = new Session('file', $dir);
        $s1->flash('msg', 'hi');   // 仅下一轮可读
        $s1->save();

        $s2 = new Session('file', $dir);
        $s2->setId($s1->getId());
        $s2->start();
        Assert::assertSame('hi', $s2->getFlash('msg')); // 本轮可读

        $s2->save(); // 消费后不再保留
        $s3 = new Session('file', $dir);
        $s3->setId($s1->getId());
        $s3->start();
        Assert::assertNull($s3->getFlash('msg'));

        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
