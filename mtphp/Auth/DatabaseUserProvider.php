<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Auth;

/**
 * 基于 Model 的用户提供者（打通 Auth 与数据库模型）
 *
 * 用法：
 *   $provider = new DatabaseUserProvider(User::class, 'email', 'password');
 *   auth()->setUserProvider([$provider, 'findById']);
 *   // 登录：auth()->attempt([$provider, 'findByCredentials'], $input);
 */
class DatabaseUserProvider
{
    protected string $model;

    protected string $loginColumn;

    protected string $passwordColumn;

    /**
     * @param class-string<\Mtphp\Database\Model> $model 模型类（需 extends Model）
     */
    public function __construct(string $model, string $loginColumn = 'email', string $passwordColumn = 'password')
    {
        $this->model          = $model;
        $this->loginColumn    = $loginColumn;
        $this->passwordColumn = $passwordColumn;
    }

    /**
     * 按主键取用户（供 Auth::setUserProvider）
     *
     * @return array<string,mixed>|null
     */
    public function findById(mixed $id): ?array
    {
        return ($this->model)::find($id);
    }

    /**
     * 按登录凭据取用户并校验密码（供 Auth::attempt）
     *
     * @param array<string,mixed> $credentials
     * @return array<string,mixed>|null
     */
    public function findByCredentials(array $credentials): ?array
    {
        $login = $credentials[$this->loginColumn] ?? null;
        if ($login === null) {
            return null;
        }
        $user = ($this->model)::findBy($this->loginColumn, $login);
        if ($user === null) {
            return null;
        }
        $hash = $user[$this->passwordColumn] ?? null;
        $plain = $credentials[$this->passwordColumn] ?? '';
        if (!is_string($hash) || !password_verify((string) $plain, $hash)) {
            return null;
        }
        return $user;
    }
}
