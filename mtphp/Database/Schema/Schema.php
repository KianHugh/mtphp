<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Schema;

use Mtphp\Database\Connector;
use Mtphp\Database\DB;

/**
 * Schema 静态门面（零依赖，思想借鉴主流框架的 Schema 外观）
 *
 * 直接把建表/改表/删表意图下发到当前数据库连接。通过 DB::connection() 拿连接，
 * 因此与 Query / Model 共用同一套连接获取策略（FPM 用缓存连接，Swoole 用协程连接池）。
 *
 * 用法：
 *   use Mtphp\Database\Schema\Schema;
 *   Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name'); });
 *   Schema::table('users', function (Blueprint $t) { $t->integer('age')->nullable(); });
 *   Schema::dropIfExists('users');
 *   $exists = Schema::hasTable('users');
 */
class Schema
{
    /**
     * 取当前数据库连接
     */
    public static function connection(): Connector
    {
        return DB::connection();
    }

    protected static function driver(): string
    {
        $conn = self::connection();
        return method_exists($conn, 'getDriver') ? $conn->getDriver() : 'mysql';
    }

    /**
     * 建表
     */
    public static function create(string $table, \Closure $callback): void
    {
        $bp = new Blueprint($table);
        $callback($bp);
        $grammar = new Grammar();
        foreach ($grammar->compileCreate($bp, self::driver()) as $sql) {
            self::connection()->statement($sql);
        }
    }

    /**
     * 改表（加列 / 加索引 / 加外键 / 删列 / 删索引 / 删外键 / 改名）
     */
    public static function table(string $table, \Closure $callback): void
    {
        $bp = new Blueprint($table);
        $callback($bp);
        $grammar = new Grammar();
        foreach ($grammar->compileAlter($bp, self::driver()) as $sql) {
            self::connection()->statement($sql);
        }
    }

    /**
     * 删表（不存在则报错）
     */
    public static function drop(string $table): void
    {
        self::connection()->statement((new Grammar())->compileDrop($table, false, self::driver()));
    }

    /**
     * 删表（不存在也不报错）
     */
    public static function dropIfExists(string $table): void
    {
        self::connection()->statement((new Grammar())->compileDrop($table, true, self::driver()));
    }

    /**
     * 判断表是否存在（跨驱动通用：试探性查询，失败即不存在）
     */
    public static function hasTable(string $table): bool
    {
        try {
            self::connection()->select('SELECT 1 FROM `' . $table . '` LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
