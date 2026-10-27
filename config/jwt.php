<?php
/**
 * JWT 配置（可选；不配置也能用，框架回退到 app.key 作密钥、HS256 作算法）
 *
 * 通过 jwt() 助手 / Mtphp\Auth\Jwt 读取。
 */
return [
    // 对称密钥（HS 系列用）；留空则回退到 config('app.key')
    // 也可从环境变量读取：getenv('JWT_SECRET')
    'secret' => getenv('JWT_SECRET') !== false ? (string) getenv('JWT_SECRET') : '',

    // 默认算法：HS256（对称）| HS384 | HS512 | RS256 | RS384 | RS512（非对称）
    'alg'    => 'HS256',

    // 默认有效期（秒），仅作约定；实际 exp 由签发方写入载荷
    'ttl'    => 3600,
];
