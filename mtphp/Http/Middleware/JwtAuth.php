<?php
declare(strict_types=1);

namespace Mtphp\Http\Middleware;

use Mtphp\Auth\Jwt;
use Mtphp\Http\Request;
use Mtphp\Http\Response;

/**
 * JWT 鉴权中间件（无状态，配合 Mtphp\Auth\Jwt）
 *
 * 从 Authorization: Bearer <token> 读取令牌，校验通过后把载荷挂到请求上
 * （$request->authUser() 可取），再向内传递；校验失败（缺失 / 过期 / 签名错）返回 401。
 *
 * 用法：把本中间件挂到需要鉴权的路由 / 路由组即可（同 AuthApi 的用法）。
 */
class JwtAuth
{
    public function handle(Request $request, callable $next): Response
    {
        $token = $this->extractToken($request);
        if ($token === null || ($payload = (new Jwt())->decode($token)) === null) {
            return (new Response())
                ->json(['code' => 401, 'message' => '未授权或令牌无效', 'data' => null], 401)
                ->header('WWW-Authenticate', 'Bearer');
        }

        $request->setAuthUser($payload);
        return $next($request);
    }

    /**
     * 从 Authorization 头提取 Bearer 令牌（无则 null）
     */
    protected function extractToken(Request $request): ?string
    {
        $auth = $request->header('authorization');
        if (!is_string($auth) || $auth === '') {
            return null;
        }
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return trim($auth);
    }
}
