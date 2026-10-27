<?php
/**
 * 上传与图片压缩配置
 *
 * 全局开关：当 compress.enabled = true 时，UploadedFile::move() 在不显式传压缩参数时
 * 也会按本配置自动压缩 / 缩放。调用方在 move() 第三参传入数组可逐次覆盖；传 false 可强制关闭。
 */
return [
    // 图片自动压缩（上传后可选对图片做缩放 + 重编码压缩）
    'compress' => [
        'enabled'     => false,    // 全局开关：true 时 UploadedFile::move() 默认压缩（可被调用方参数覆盖）
        'quality'     => 82,       // jpeg / webp 质量 0-100（越大越清晰、文件越大）
        'max_width'   => 1920,     // 超过则等比缩小（0 = 不限）
        'max_height'  => 1920,     // 超过则等比缩小（0 = 不限）
        'format'      => 'keep',   // keep（保持原格式）| jpeg | png | webp
        'auto_rotate' => true,     // 按 EXIF Orientation 自动转正（仅 jpeg，需 exif 扩展）

        // 说明：
        //  - GD 重新编码天然不保留 EXIF/IPTC 元数据，故元数据总是被剥离（无需单独开关）。
        //  - 动画 GIF 在 format=keep 时自动跳过压缩（imagegif 只存首帧会丢动画），原样保留。
        //  - WebP 仅在运行环境支持 imagewebp 时生效，否则回退 jpeg。
    ],
];
