<?php
declare(strict_types=1);

/**
 * Auth 认证守卫测试
 * 运行：php8 tests/run.php AuthTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Auth\Auth;
use Mtphp\Http\Session;

class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        // 用一个稳定的内存会话作为“当前会话”，保证 Auth 跨调用数据一致
        Session::clearCurrent();
        Session::setCurrent(Session::instance());
    }

    protected function tearDown(): void
    {
        Session::clearCurrent();
    }

    public function testLoginCheckUserLogout(): void
    {
        $auth = new Auth();
        $auth->setUserProvider(function ($id) {
            return ['id' => $id, 'name' => 'U' . $id];
        });

        Assert::assertTrue($auth->guest());
        $auth->login(['id' => 5, 'name' => 'U5']);
        Assert::assertTrue($auth->check());
        Assert::assertSame(5, $auth->id());
        $user = $auth->user();
        Assert::assertSame('U5', $user['name']);

        $auth->logout();
        Assert::assertFalse($auth->check());
        Assert::assertNull($auth->user());
    }

    public function testAttemptWithCredentials(): void
    {
        $auth = new Auth();
        $users = ['alice' => ['id' => 1, 'email' => 'alice', 'password' => password_hash('secret', PASSWORD_DEFAULT)]];

        $auth->setUserProvider(function ($id) use ($users) {
            foreach ($users as $u) {
                if ($u['id'] === $id) {
                    return $u;
                }
            }
            return null;
        });

        $resolver = function (array $creds) use ($users) {
            $u = $users[$creds['email'] ?? ''] ?? null;
            if ($u === null || !password_verify($creds['password'] ?? '', $u['password'])) {
                return null;
            }
            return $u;
        };

        Assert::assertTrue($auth->attempt($resolver, ['email' => 'alice', 'password' => 'secret']));
        Assert::assertTrue($auth->check());

        $auth2 = new Auth();
        $auth2->setUserProvider(function ($id) {
            return null;
        });
        Assert::assertFalse($auth2->attempt($resolver, ['email' => 'alice', 'password' => 'wrong']));
    }
}
