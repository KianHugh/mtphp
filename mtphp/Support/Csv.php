<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * CSV 读写（零依赖，基于 fgetcsv / fputcsv）
 *
 * 用法：
 *   $rows = Csv::parse($csvString);           // 首行为表头 → 关联数组列表
 *   $csv  = Csv::toCsv([['a'=>1],['a'=>2]]);  // 自动取首行键为表头
 *   Csv::toCsv($rows, ['col1','col2']);       // 显式指定表头
 */
class Csv
{
    /**
     * 解析 CSV 字符串
     *
     * @param string $csv
     * @param bool   $header 首行是否作为表头（true 时返回关联数组）
     * @return array<int,array<int|string,mixed>>
     */
    public static function parse(string $csv, bool $header = true, string $delimiter = ',', string $enclosure = '"'): array
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return [];
        }
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, $enclosure)) !== false) {
            if ($row === [''] || $row === [null]) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        if ($rows === []) {
            return [];
        }
        if (!$header) {
            return $rows;
        }
        $head = array_shift($rows);
        $out  = [];
        foreach ($rows as $r) {
            $out[] = array_combine($head, $r);
        }
        return $out;
    }

    /**
     * 解析 CSV 文件
     *
     * @return array<int,array<int|string,mixed>>
     */
    public static function parseFile(string $path, bool $header = true, string $delimiter = ',', string $enclosure = '"'): array
    {
        if (!is_file($path)) {
            return [];
        }
        $csv = (string) file_get_contents($path);
        return self::parse($csv, $header, $delimiter, $enclosure);
    }

    /**
     * 数组转 CSV 字符串
     *
     * @param array<int,array<int|string,mixed>> $rows
     * @param list<string>|null                 $header 显式表头；为 null 且行为关联数组时取首行键
     */
    public static function toCsv(array $rows, ?array $header = null, string $delimiter = ',', string $enclosure = '"', string $eol = "\n"): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        if ($header !== null) {
            fputcsv($handle, $header, $delimiter, $enclosure);
        } elseif (!empty($rows) && array_is_list($rows) && is_array($rows[0])) {
            fputcsv($handle, array_keys($rows[0]), $delimiter, $enclosure);
        }

        foreach ($rows as $r) {
            if (is_array($r)) {
                fputcsv($handle, array_values($r), $delimiter, $enclosure);
            }
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        // 替换 fputcsv 默认换行符为期望的 eol（保持跨平台一致）
        if ($eol !== "\n") {
            $csv = str_replace("\n", $eol, $csv);
        }
        return $csv;
    }
}
