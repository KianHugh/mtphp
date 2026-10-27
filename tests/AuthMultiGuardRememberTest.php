<?php
declare(strict_types=1);

/**
 * Auth 多守卫隔离 + 记住我 测试（零外部依赖）
 *   · new Auth() 基础登录态（向后兼容）
 *   · auth('web') / auth('admin') 守卫隔离
 *   · 记住我：login(remember) → 下发 Cookie；会话失效后凭 Cookie 自动重登录；logout 清除
 * 运行：php8 tests/run.php AuthMultiGuardRememberTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Auth\Auth;
use Mtphp\Auth\AuthManager;
use Mtphp\Http\Session;
use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;

class AuthMultiGuardRememberTest extends TestCase
{
    protected function setUp(): void
    {
        Session::clearCurrent();
        Session::setCurrent(new Session());
        AuthManager::reset();
    }

    protected function tearDown(): void
    {
        Session::clearCurrent();
        AuthManager::reset();
    }

    private function makeGuard(string $name = 'web'): Auth
    {
        $guard = new Auth($name);
        $guard->setUserProvider(function ($id) use ($name) {
            return ['id' => $id, 'name' => $name . 'U' . $id, 'remember_token' => 'tok_' . $id];
        });
        // 记住我解析器：校验 id + token 匹配
        $guard->setRememberResolver(function (int $id, string $token) {
            if ($token === 'tok_' . $id) {
                return ['id' => $id, 'name' => 'U' . $id, 'remember_token' => $token];
            }
            return null;
        });
        return $guard;
    }

    public function testBasicLoginCheckUserLogout(): void
    {
        $auth = new Auth();
        $auth->setUserProvider(fn ($id) => ['id' => $id, 'name' => 'U' . $id]);

        Assert::assertTrue($auth->guest());
        $auth->login(['id' => 5, 'name' => 'U5']);
        Assert::assertTrue($auth->check());
        Assert::assertSame(5, $auth->id());
        Assert::assertSame('U5', $auth->user()['name']);
        $auth->logout();
        Assert::assertFalse($auth->check());
        Assert::assertNull($auth->user());
    }

    public function testGuardsAreIsolated(): void
    {
        $web   = $this->makeGuard('web');
        $admin = $this->makeGuard('admin');

        $web->login(['id' => 1, 'name' => 'webU1']);
        Assert::assertTrue($web->check());
        // admin 守卫未登录（隔离）
        Assert::assertFalse($admin->check());
        Assert::assertNull($admin->id());

        $admin->login(['id' => 9, 'name' => 'adminU9']);
        Assert::assertSame(1, $web->id());
        Assert::assertSame(9, $admin->id());
    }

    public function testAuthHelperReturnsDefaultGuard(): void
    {
        $g = auth();
        Assert::assertTrue($g instanceof Auth);
        Assert::assertSame('web', $g->name());
        // 同一请求内按名复用
        Assert::assertSame(auth(), auth());
        Assert::assertSame(auth('admin'), auth('admin'));
        Assert::assertTrue(auth() !== auth('admin'));
    }

    public function testRememberMeIssuesCookieOnLogin(): void
    {
        $cookieStore = [];
        $guard = $this->makeGuard('web');
        $guard->setRememberWriter(function (string $value, int $expireAt) use (&$cookieStore): void {
            $cookieStore['remember_web'] = $value;
        });

        $guard->login(['id' => 7, 'name' => 'U7', 'remember_token' => 'tok_7'], true);

        Assert::assertTrue(isset($cookieStore['remember_web']));
        Assert::assertSame('7|tok_7', $cookieStore['remember_web']);
    }

    public function testRememberMeAutoReLoginWhenSessionLost(): void
    {
        $cookieStore = ['remember_web' => '7|tok_7'];
        $guard = $this->makeGuard('web');
        $guard->setRememberReader(fn () => $cookieStore['remember_web'] ?? null);
        $guard->setRememberWriter(function (string $value, int $expireAt) use (&$cookieStore): void {
            $cookieStore['remember_web'] = $value;
        });

        // 模拟新请求：会话为空，但记住我 Cookie 仍在
        Session::clearCurrent();
        Session::setCurrent(new Session());

        Assert::assertFalse($guard->check());           // 会话确实已失效
        $user = $guard->user();                          // 应凭 Cookie 自动重登录
        Assert::assertNotNull($user);
        Assert::assertSame(7, $user['id']);
        Assert::assertTrue($guard->check());             // 重登录后已恢复
    }

    public function testRememberMeRejectsBadToken(): void
    {
        $cookieStore = ['remember_web' => '7|wrong_token'];
        $guard = $this->makeGuard('web');
        $guard->setRememberReader(fn () => $cookieStore['remember_web'] ?? null);

        Session::clearCurrent();
        Session::setCurrent(new Session());

        Assert::assertNull($guard->user());              // 令牌不匹配 → 续期失败
        Assert::assertFalse($guard->check());
    }

    public function testLogoutClearsRememberCookie(): void
    {
        $cookieStore = [];
        $guard = $this->makeGuard('web');
        $guard->setRememberWriter(function (string $value, int $expireAt) use (&$cookieStore): void {
            $cookieStore['remember_web'] = $value;
        });

        $guard->login(['id' => 3, 'name' => 'U3', 'remember_token' => 'tok_3'], true);
        Assert::assertSame('3|tok_3', $cookieStore['remember_web']);

        $guard->logout();
        Assert::assertSame('', $cookieStore['remember_web']);  // Cookie 已置空（失效）
        Assert::assertFalse($guard->check());
    }

    public function testRememberGeneratesTokenWhenMissing(): void
    {
        $cookieStore = [];
        $persisted = [];
        $guard = new Auth('web');
        $guard->setUserProvider(fn ($id) => ['id' => $id, 'name' => 'U' . $id]);
        $guard->setRememberResolver(fn (int $id, string $token) => $token === 'new_tok'
            ? ['id' => $id, 'name' => 'U' . $id, 'remember_token' => $token]
            : null);
        $guard->setRememberPersister(function (int $id, string $token) use (&$persisted): void {
            $persisted[$id] = $token;
        });
        $guard->setRememberWriter(function (string $value, int $expireAt) use (&$cookieStore): void {
            $cookieStore['remember_web'] = $value;
        });
        $guard->setTokenGenerator(fn () => 'new_tok');

        // 用户数据里没有 remember_token → 登录时应自动生成并持久化
        $guard->login(['id' => 4, 'name' => 'U4'], true);

        Assert::assertSame('new_tok', $persisted[4] ?? null);   // 已持久化
        Assert::assertSame('4|new_tok', $cookieStore['remember_web'] ?? null);
    }
}
