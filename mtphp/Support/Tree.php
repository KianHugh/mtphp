<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Support;

/**
 * 树形结构处理（零依赖）
 *
 * 扁平列表（带 parent_id）与树形之间的转换，以及祖先/后代查询、深度计算。
 * 常用于：部门、分类、菜单、权限树。
 *
 * 用法：
 *   $tree = Tree::build($flatList, 'id', 'parent_id', 'children');
 *   $flat = Tree::flatten($tree);
 *   $anc  = Tree::ancestors($flatList, 12);     // 12 的所有上级
 *   $desc = Tree::descendants($flatList, 1);    // 1 的所有下级
 *   $d    = Tree::maxDepth($tree);
 */
class Tree
{
    /**
     * 扁平列表 → 嵌套树（根为无父 / 父不存在的项）
     *
     * @param array<int,array<string,mixed>> $flat
     * @return array<int,array<string,mixed>>
     */
    public static function build(array $flat, string $idKey = 'id', string $parentKey = 'parent_id', string $childrenKey = 'children'): array
    {
        $nodes = [];
        foreach ($flat as $item) {
            $nodes[$item[$idKey]] = $item;
        }
        foreach ($nodes as &$node) {
            $node[$childrenKey] = [];
        }
        unset($node);

        $roots = [];
        foreach ($nodes as $id => &$node) {
            $pid = $node[$parentKey] ?? null;
            if ($pid !== null && $pid !== '' && isset($nodes[$pid])) {
                $nodes[$pid][$childrenKey][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        // 解除引用并返回（避免调用方拿到引用数组引发的副作用）
        return json_decode((string) json_encode($roots), true) ?: [];
    }

    /**
     * 嵌套树 → 扁平列表（去掉 children 键）
     *
     * @param array<int,array<string,mixed>> $tree
     * @return array<int,array<string,mixed>>
     */
    public static function flatten(array $tree, string $childrenKey = 'children'): array
    {
        $out   = [];
        $stack = array_values($tree);
        while ($stack !== []) {
            $node  = array_pop($stack);
            $kids  = $node[$childrenKey] ?? [];
            unset($node[$childrenKey]);
            $out[] = $node;
            foreach (array_reverse($kids) as $kid) {
                $stack[] = $kid;
            }
        }
        return $out;
    }

    /**
     * 取某节点的所有祖先（由近到远）
     *
     * @param array<int,array<string,mixed>> $flat
     * @return array<int,array<string,mixed>>
     */
    public static function ancestors(array $flat, mixed $id, string $idKey = 'id', string $parentKey = 'parent_id'): array
    {
        $map = [];
        foreach ($flat as $item) {
            $map[$item[$idKey]] = $item;
        }
        $out = [];
        $cur = $id;
        while (isset($map[$cur])) {
            $pid = $map[$cur][$parentKey] ?? null;
            if ($pid === null || $pid === '' || !isset($map[$pid])) {
                break;
            }
            $out[] = $map[$pid];
            $cur   = $pid;
        }
        return $out;
    }

    /**
     * 取某节点的所有后代（深度优先）
     *
     * @param array<int,array<string,mixed>> $flat
     * @return array<int,array<string,mixed>>
     */
    public static function descendants(array $flat, mixed $id, string $idKey = 'id', string $parentKey = 'parent_id'): array
    {
        $childrenMap = [];
        foreach ($flat as $item) {
            $pid = $item[$parentKey] ?? null;
            if ($pid !== null && $pid !== '') {
                $childrenMap[$pid][] = $item;
            }
        }
        $out    = [];
        $queue  = [$id];
        while ($queue !== []) {
            $cur = array_pop($queue);
            if (!isset($childrenMap[$cur])) {
                continue;
            }
            foreach ($childrenMap[$cur] as $child) {
                $out[]    = $child;
                $queue[]  = $child[$idKey];
            }
        }
        return $out;
    }

    /**
     * 树的最大深度
     *
     * @param array<int,array<string,mixed>> $tree
     */
    public static function maxDepth(array $tree, string $childrenKey = 'children'): int
    {
        if ($tree === []) {
            return 0;
        }
        $max = 0;
        foreach ($tree as $node) {
            $kids = $node[$childrenKey] ?? [];
            $d    = 1 + self::maxDepth($kids, $childrenKey);
            if ($d > $max) {
                $max = $d;
            }
        }
        return $max;
    }
}
