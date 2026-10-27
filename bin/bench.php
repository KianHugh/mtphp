#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 极简 HTTP 压测工具（零依赖，仅依赖 curl 扩展）
 *
 * 用 curl_multi 实现固定并发的“总请求数”压测，输出吞吐（RPS）与延迟分位。
 *
 * 用法：
 *   php8 bin/bench.php <URL> [总请求数=500] [并发数=20]
 *
 * 示例：
 *   php8 bin/bench.php http://127.0.0.1:8080/ 1000 50
 *
 * 两种目标服务器说明（重要）：
 *   - Windows 演示用「内置服务器」php -S 是单工作进程、单线程，无法代表生产性能；
 *     本工具在此场景下只用于“功能/回归级”验证（吞吐是否随并发线性提升、有无报错）。
 *   - 生产级数字请在「Linux / WSL + Swoole 常驻服务器」上跑，并用 wrk / ab 获取：
 *       wrk -t4 -c100 -d10s http://127.0.0.1:9501/
 *       ab  -n10000 -c100    http://127.0.0.1:9501/
 *     （Swoole 常驻 + 协程连接池是高并发关键，见使用手册第 18 节）
 */

if (!extension_loaded('curl')) {
    fwrite(STDERR, "错误：本工具需要 curl 扩展，请先启用。\n");
    exit(2);
}

$url   = $argv[1] ?? '';
$total = (int) ($argv[2] ?? 500);
$conc  = (int) ($argv[3] ?? 20);
$timeout = 10;

if ($url === '' || $total <= 0 || $conc <= 0) {
    fwrite(STDERR, "用法: php8 bin/bench.php <URL> [总请求数=500] [并发数=20]\n");
    exit(2);
}

echo sprintf("压测目标 %s   总请求=%d   并发=%d\n", $url, $total, $conc);

$mh = curl_multi_init();
$map = [];      // spl_object_id => ['ch' => CurlHandle, 't0' => float]
$sent = 0;
$done = 0;
$errors = 0;
$latencies = [];
$start = microtime(true);

// 新建一个请求句柄并记录起始时间（微秒）
$newHandle = static function () use ($url, $timeout): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Connection: keep-alive']);
    return ['ch' => $ch, 't0' => microtime(true)];
};

// 主循环：保持并发池中有 $conc 个在途请求，直到全部完成
while ($done < $total) {
    // ① 补充并发：在途数 < 并发上限 且 还有未发请求时，持续入池
    while (count($map) < $conc && $sent < $total) {
        $h = $newHandle();
        $id = spl_object_id($h['ch']);
        $map[$id] = $h;
        curl_multi_add_handle($mh, $h['ch']);
        $sent++;
    }

    // ② 驱动多句柄（非阻塞推进）
    do {
        $status = curl_multi_exec($mh, $active);
    } while ($status === CURLM_CALL_MULTI_PERFORM);

    // ③ 收割已完成请求
    while (($info = curl_multi_info_read($mh)) !== false) {
        $ch = $info['handle'];
        $id = spl_object_id($ch);
        $rec = $map[$id] ?? null;
        $t0 = $rec['t0'] ?? $start;
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lat = microtime(true) - $t0;

        // 网络错误 或 5xx 视为失败
        if ($err !== '' || $code >= 500) {
            $errors++;
        } else {
            $latencies[] = $lat;
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($map[$id]);
        $done++;
    }

    // ④ 让出 CPU，避免空转忙等（有在途请求时）
    if (count($map) > 0) {
        curl_multi_select($mh, 0.01);
    }
}

$end = microtime(true);
curl_multi_close($mh);

$elapsed = $end - $start;
$rps = $elapsed > 0 ? $done / $elapsed : 0;

if ($latencies !== []) {
    sort($latencies);
    $n = count($latencies);
    $min = $latencies[0];
    $max = $latencies[$n - 1];
    $avg = array_sum($latencies) / $n;
    $p50 = $latencies[(int) floor($n * 0.50)];
    $p95 = $latencies[(int) floor($n * 0.95)];
    $p99 = $latencies[(int) floor($n * 0.99)];
} else {
    $min = $max = $avg = $p50 = $p95 = $p99 = 0.0;
}

echo str_repeat('=', 52) . "\n";
echo sprintf("总请求    : %d\n", $done);
echo sprintf("成功/失败 : %d / %d\n", $done - $errors, $errors);
echo sprintf("总耗时    : %.3f s\n", $elapsed);
echo sprintf("吞吐 RPS  : %.1f\n", $rps);
echo sprintf("延迟(ms)  : 最小 %.1f / 平均 %.1f / 最大 %.1f\n", $min * 1000, $avg * 1000, $max * 1000);
echo sprintf("分位(ms)  : P50 %.1f / P95 %.1f / P99 %.1f\n", $p50 * 1000, $p95 * 1000, $p99 * 1000);
echo ($errors === 0 ? "✅ 压测完成\n" : "⚠️ 存在失败请求\n");

exit($errors === 0 ? 0 : 1);
