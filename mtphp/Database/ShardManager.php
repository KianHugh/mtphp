<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database;

/**
 * 分库（水平拆分）路由助手（零依赖，思想借鉴主流框架的 shard 路由，独立实现）
 *
 * 一张“逻辑表”按分片键（如 user_id / order_id）分散到多个物理库；每个物理库在
 * config/database.php 的 connections 里是一个独立连接名。本类只负责“算路由”不持有
 * 连接，因此可在无 App / 无数据库环境下直接单测。
 *
 * 两种算法：
 *  - hash ：数字键按值取模（便于按主键预览落片），字符串键按 crc32 取模；适合均分；
 *  - range：按单调递增边界把键落到对应分片（如 0~999 在 shard0，1000~1999 在 shard1）。
 *
 * 配合 DB::shard() 使用：
 *   DB::shard('orders', $orderId)->table('orders')->where('id', $orderId)->get();
 */
final class ShardManager
{
    /**
     * 计算分片键对应的分片索引
     *
     * @param mixed        $key        分片键（数字或字符串均可）
     * @param list<string> $shards     分片连接名列表（顺序即分片编号）
     * @param string       $algorithm  hash | range
     * @param list<mixed>  $boundaries range 模式下的单调递增边界（升序）
     */
    public static function index(mixed $key, array $shards, string $algorithm = 'hash', array $boundaries = []): int
    {
        $n = count($shards);
        if ($n === 0) {
            throw new \RuntimeException('分片列表不能为空');
        }

        if ($algorithm === 'range') {
            return self::rangeIndex($key, $boundaries, $n);
        }

        // 默认 hash：数字键按值取模（便于按主键预览落片），字符串键按 crc32 取模
        $hash = is_numeric($key)
            ? (int) $key
            : (function_exists('crc32') ? crc32((string) $key) : self::simpleHash((string) $key));
        return (int) (abs($hash) % $n);
    }

    /**
     * range：返回第一个“边界 > 键”的分片索引；超出全部边界则落到最后一个分片。
     *
     * @param list<mixed> $boundaries
     */
    protected static function rangeIndex(mixed $key, array $boundaries, int $n): int
    {
        if ($boundaries === []) {
            throw new \RuntimeException('range 算法需要配置 boundaries（单调递增边界）');
        }
        foreach ($boundaries as $i => $b) {
            if ($key < $b) {
                return $i;
            }
        }
        return $n - 1;
    }

    /**
     * 从分库组配置中取出物理分片连接名列表（纯函数，便于无 App 环境单测）
     *
     * @param array<string,mixed> $cfg 形如 ['shards' => ['s0','s1'], 'algorithm' => 'hash', ...]
     * @return list<string>
     */
    public static function shardsFromConfig(array $cfg): array
    {
        if (empty($cfg['shards']) || !is_array($cfg['shards'])) {
            throw new \RuntimeException('分库配置缺少 shards（物理连接名列表）');
        }
        return array_values((array) $cfg['shards']);
    }

    /**
     * 纯 PHP 字符串哈希（当环境无 crc32 时的兜底，保证确定性）
     */
    protected static function simpleHash(string $s): int
    {
        $h = 0;
        for ($i = 0, $l = strlen($s); $i < $l; $i++) {
            $h = (int) (($h * 31 + ord($s[$i])) & 0x7FFFFFFF);
        }
        return $h;
    }
}
