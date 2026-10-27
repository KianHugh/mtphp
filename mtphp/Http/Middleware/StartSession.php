<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Http\Middleware;

use Mtphp\App;
use Mtphp\Http\Request;
use Mtphp\Http\Response;
use Mtphp\Http\Session;

/**
 * 会话启动中间件（框架内置，按需挂到路由/全局中间件）
 *
 * 职责：读取会话 Cookie → 加载（或新建）会话 → 注入 Request → 执行后续 → 保存会话 → 下发 Cookie。
 * 与传输解耦：Cookie 的收发在这里完成，Session 本身只管数据存取。
 *
 * 启用方式：在 config/app.php 的 app.middleware 里加入 Mtphp\Http\Middleware\StartSession::class，
 * 或在某组路由上单独挂载。
 */
class StartSession
{
    public const COOKIE = 'mtphp_session';

    public function handle(Request $request, callable $next): Response
    {
        $app    = App::instance();
        $cfg    = $app !== null ? $app->config() : null;
        $driver = $cfg !== null ? (string) $cfg->get('session.driver', 'file') : 'file';
        $path   = $cfg !== null
            ? ($cfg->get('session.path') ?: $app->root() . '/runtime/sessions')
            : sys_get_temp_dir();
        $lifetime = $cfg !== null ? (int) $cfg->get('session.lifetime', 120) : 120;

        $session = new Session($driver, (string) $path);
        $id      = $request->cookie(self::COOKIE);
        if (is_string($id) && $id !== '') {
            $session->setId($id);
        }
        $session->start();
        $request->setSession($session);
        if ($app !== null) {
            $app->setSession($session);
        }

        $response = $next($request);

        $session->save();
        $response->cookie(self::COOKIE, $session->getId(), time() + 60 * $lifetime, [
            'path'     => '/',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $response;
    }

    protected function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
    }
}
