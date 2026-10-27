<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database;

/**
 * 软删除（trait，零依赖）
 *
 * 用法：在模型上 use SoftDeletes；表需含可空时间戳列 deleted_at（Schema 提供 $t->softDeletes()）。
 *  - 默认查询自动隐藏已删除记录（全局作用域 WHERE deleted_at IS NULL）；
 *  - delete() 改为“置 deleted_at”，而非物理删除；
 *  - 提供 withTrashed() / onlyTrashed() / restore() / forceDelete() 绕过或操作软删记录。
 *
 * 思想借鉴主流框架的 SoftDeletes（独立实现）。
 */
trait SoftDeletes
{
    /** 软删除标记列名（子类可覆盖） */
    protected static string $deletedAtColumn = 'deleted_at';

    public static function deletedAtColumn(): string
    {
        return static::$deletedAtColumn;
    }

    /**
     * 覆盖 Model::query()：默认追加“未删除”作用域
     */
    public static function query(): Query
    {
        return parent::query()->whereNull(static::deletedAtColumn());
    }

    /**
     * 不带软删除作用域的查询构造器（供 withTrashed / restore / forceDelete 内部使用）
     */
    protected static function baseQuery(): Query
    {
        return parent::query();
    }

    /**
     * 包含软删除记录
     */
    public static function withTrashed(): ModelBuilder
    {
        return static::baseQuery();
    }

    /**
     * 仅软删除记录
     */
    public static function onlyTrashed(): ModelBuilder
    {
        return static::baseQuery()->whereNotNull(static::deletedAtColumn());
    }

    /**
     * 软删除：置 deleted_at（而非物理删除）
     */
    public static function delete(mixed $id): int
    {
        $payload = ['id' => $id];
        if (static::fireModelEvent('deleting', $payload) === false) {
            return 0;
        }
        $affected = static::baseQuery()
            ->where(static::primaryKey(), '=', $id)
            ->update([static::deletedAtColumn() => date('Y-m-d H:i:s')]);
        static::fireModelEvent('deleted', $payload);
        return $affected;
    }

    /**
     * 恢复被软删记录（deleted_at 置 NULL）
     */
    public static function restore(mixed $id): int
    {
        $payload = ['id' => $id];
        if (static::fireModelEvent('restoring', $payload) === false) {
            return 0;
        }
        $affected = static::baseQuery()
            ->where(static::primaryKey(), '=', $id)
            ->update([static::deletedAtColumn() => null]);
        static::fireModelEvent('restored', $payload);
        return $affected;
    }

    /**
     * 物理删除（绕过软删除作用域）
     */
    public static function forceDelete(mixed $id): int
    {
        $payload = ['id' => $id];
        if (static::fireModelEvent('forceDeleting', $payload) === false) {
            return 0;
        }
        $affected = static::baseQuery()
            ->where(static::primaryKey(), '=', $id)
            ->delete();
        static::fireModelEvent('forceDeleted', $payload);
        return $affected;
    }

    /**
     * 判断某条记录当前是否处于“软删除”状态（deleted_at 不为 NULL）。
     *
     *    if (User::trashed($id)) { /* 已被软删 *\/ }
     *
     * 走 baseQuery（绕过“未删除”作用域），只看 deleted_at 列。
     */
    public static function trashed(mixed $id): bool
    {
        $row = static::baseQuery()
            ->where(static::primaryKey(), '=', $id)
            ->whereNotNull(static::deletedAtColumn())
            ->first();
        return $row !== null;
    }
}
