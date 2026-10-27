<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * XML 与数组互转（零依赖，基于 SimpleXML + DOMDocument 内置扩展）
 *
 * 约定（toXml 的输入数组）：
 *   - 普通键值 => 子元素（键为标签名）
 *   - '@attributes' => 该元素的属性数组
 *   - '@value' / '#text' => 文本节点
 *   - '@cdata' => CDATA 节点
 *   - 值为数字索引数组（列表）=> 同名元素重复出现
 *
 * parse 的输出遵循同样约定（属性进 '@attributes'，文本进 '@value'，同名多元素进列表）。
 *
 * 用法：
 *   $arr = Xml::parse($xmlString);          // string|file → array
 *   $xml = Xml::toXml(['user' => ['name' => '张三']], 'root');
 *   Xml::toXml(['item' => [['id'=>1],['id'=>2]]]);  // 重复 <item>
 */
class Xml
{
    /**
     * 解析 XML 字符串为数组（解析失败返回空数组）
     *
     * @return mixed
     */
    public static function parse(string $xml, bool $assoc = true): mixed
    {
        $xml = trim($xml);
        if ($xml === '') {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        $sx   = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($sx === false) {
            return [];
        }
        return self::elementToArray($sx);
    }

    /**
     * 解析 XML 文件为数组
     *
     * @return mixed
     */
    public static function parseFile(string $path, bool $assoc = true): mixed
    {
        if (!is_file($path)) {
            return [];
        }
        return self::parse((string) file_get_contents($path), $assoc);
    }

    /**
     * 数组转 XML 字符串
     *
     * @param array<int|string,mixed> $data
     */
    public static function toXml(array $data, string $root = 'root', array $config = []): string
    {
        $encoding = $config['encoding'] ?? 'UTF-8';
        $pretty   = $config['pretty'] ?? true;

        $dom = new \DOMDocument('1.0', $encoding);
        $dom->formatOutput = $pretty;
        $dom->preserveWhiteSpace = false;

        $rootEl = $dom->createElement($root);
        $dom->appendChild($rootEl);
        self::buildNode($dom, $rootEl, $data);

        $xml = $dom->saveXML();
        return $xml === false ? '' : $xml;
    }

    // ------------------------------------------------------------------
    // 内部：数组 → DOM
    // ------------------------------------------------------------------

    /**
     * @param array<int|string,mixed> $data
     */
    protected static function buildNode(\DOMDocument $dom, \DOMElement $parent, array $data): void
    {
        foreach ($data as $key => $value) {
            if (is_int($key) || ctype_digit((string) $key)) {
                continue; // 列表项由其父级以同名元素处理
            }
            $key = (string) $key;

            if ($key === '@attributes') {
                if (is_array($value)) {
                    foreach ($value as $ak => $av) {
                        $parent->setAttribute((string) $ak, self::stringify($av));
                    }
                }
                continue;
            }
            if ($key === '@value' || $key === '#text') {
                $parent->appendChild($dom->createTextNode(self::stringify($value)));
                continue;
            }
            if ($key === '@cdata') {
                $parent->appendChild($dom->createCDATASection(self::stringify($value)));
                continue;
            }

            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        $child = $dom->createElement($key);
                        if (is_array($item)) {
                            self::buildNode($dom, $child, $item);
                        } else {
                            $child->appendChild($dom->createTextNode(self::stringify($item)));
                        }
                        $parent->appendChild($child);
                    }
                } else {
                    $child = $dom->createElement($key);
                    self::buildNode($dom, $child, $value);
                    $parent->appendChild($child);
                }
            } else {
                $child = $dom->createElement($key);
                $child->appendChild($dom->createTextNode(self::stringify($value)));
                $parent->appendChild($child);
            }
        }
    }

    protected static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }

    // ------------------------------------------------------------------
    // 内部：SimpleXML → 数组
    // ------------------------------------------------------------------

    /**
     * @return mixed
     */
    protected static function elementToArray(\SimpleXMLElement $el): mixed
    {
        $result = [];

        $attributes = $el->attributes();
        if ($attributes !== null && count($attributes) > 0) {
            foreach ($attributes as $ak => $av) {
                $result['@attributes'][(string) $ak] = (string) $av;
            }
        }

        $children = $el->children();
        if (count($children) === 0) {
            $text = trim((string) $el);
            if (isset($result['@attributes'])) {
                $result['@value'] = $text;
                return $result;
            }
            return $text;
        }

        $grouped = [];
        foreach ($children as $name => $child) {
            $grouped[(string) $name][] = $child;
        }
        foreach ($grouped as $name => $list) {
            if (count($list) === 1) {
                $result[$name] = self::elementToArray($list[0]);
            } else {
                $result[$name] = array_map([self::class, 'elementToArray'], $list);
            }
        }
        return $result;
    }
}
