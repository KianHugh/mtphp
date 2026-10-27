<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Cache;

/**
 * Redis RESP 协议编解码（纯 PHP 实现，零依赖，无需 ext-redis / predis）
 *
 * 仅实现缓存所需的子集：请求编码（数组命令 → 多行批量协议）、响应解码
 * （简单字符串 / 错误 / 整数 / 批量字符串 / 数组，含嵌套）。
 *
 * 参考：https://redis.io/docs/reference/protocol-spec/
 */
final class Resp
{
    /**
     * 不完整的响应（数据还没收齐），由连接器捕获后继续读取
     */
    public static function incomplete(): RespIncomplete
    {
        return new RespIncomplete();
    }

    /**
     * 编码一条命令（数组参数 → RESP 多行批量协议）
     *
     * @param list<string|int> $args
     */
    public static function encode(array $args): string
    {
        $s = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $a = (string) $a;
            $s .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
        }
        return $s;
    }

    /**
     * 从字符串开头解码一条完整响应；若缓冲区在中途结束则抛出 RespIncomplete
     *
     * @return mixed
     */
    public static function decode(string $buf)
    {
        $pos = 0;
        $len = strlen($buf);

        $readLine = function () use (&$pos, $buf, $len): string {
            $start = $pos;
            while ($pos < $len) {
                if ($buf[$pos] === "\n") {
                    $line = substr($buf, $start, $pos - $start);
                    if ($line !== '' && $line[strlen($line) - 1] === "\r") {
                        $line = substr($line, 0, -1);
                    }
                    $pos++;
                    return $line;
                }
                $pos++;
            }
            throw self::incomplete();
        };

        $readN = function (int $n) use (&$pos, $buf, $len): string {
            if ($pos + $n > $len) {
                throw self::incomplete();
            }
            $s = substr($buf, $pos, $n);
            $pos += $n;
            return $s;
        };

        $parse = function () use (&$parse, &$pos, $buf, $len, $readLine, $readN) {
            if ($pos >= $len) {
                throw self::incomplete();
            }
            $type = $buf[$pos];
            $pos++;
            switch ($type) {
                case '+': // 简单字符串
                    return $readLine();

                case '-': // 错误
                    throw new \RuntimeException('Redis 错误：' . $readLine());

                case ':': // 整数
                    return (int) $readLine();

                case '$': // 批量字符串
                    $n = (int) $readLine();
                    if ($n === -1) {
                        return null;
                    }
                    $data = $readN($n);
                    $readN(2); // 消费 \r\n
                    return $data;

                case '*': // 数组
                    $count = (int) $readLine();
                    if ($count === -1) {
                        return null;
                    }
                    $out = [];
                    for ($i = 0; $i < $count; $i++) {
                        $out[] = $parse();
                    }
                    return $out;

                default:
                    throw new \RuntimeException('非预期的 RESP 类型：' . var_export($type, true));
            }
        };

        return $parse();
    }
}

/**
 * 响应不完整异常（内部使用，连接器据此继续读取）
 */
class RespIncomplete extends \RuntimeException
{
}
