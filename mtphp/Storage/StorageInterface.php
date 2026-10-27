<?php
declare(strict_types=1);

namespace Mtphp\Storage;

/**
 * 存储驱动统一接口
 *
 * 屏蔽本地磁盘 / 对象存储（七牛 / 阿里云 OSS / 腾讯云 COS …）的差异，
 * 业务层只依赖本接口，切换驱动零改动代码。
 *
 * 云驱动不在框架内内置（需 composer 引入对应 SDK 后用 StorageManager::extend 注册）；
 * 框架只交付本地磁盘驱动 + 本接口，作为“可插拔”的契约。
 */
interface StorageInterface
{
    /**
     * 写入内容；成功返回 true
     */
    public function put(string $path, string $contents, array $options = []): bool;

    /**
     * 读取内容；不存在返回 null
     */
    public function get(string $path): ?string;

    /**
     * 是否存在
     */
    public function exists(string $path): bool;

    /**
     * 删除；不存在返回 false
     */
    public function delete(string $path): bool;

    /**
     * 文件大小（字节）；不存在返回 null
     */
    public function size(string $path): ?int;

    /**
     * MIME 类型；不存在返回 null
     */
    public function mime(string $path): ?string;

    /**
     * 可公开访问的 URL（本地盘为 base url + path；云盘为对象外链）
     */
    public function url(string $path): string;

    /**
     * 临时签名访问 URL；不支持的驱动返回 null（本地盘无意义）
     */
    public function tempUrl(string $path, int $ttl): ?string;
}
