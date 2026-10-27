<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Translation;

/**
 * 轻量本地化器（零依赖）
 *
 * 设计目标：用最简单的方式支持多语言文案，支持点号分组（group.key）、占位符 :name 替换、
 * 以及 fallback 回退语言。文案可通过 addLines() 内联注入，也可后续扩展为加载语言文件。
 *
 * 用法：
 *   __('welcome', ['name' => '张三']);          // 输出 “欢迎，张三”
 *   Translator::instance()->addLines([
 *       'welcome' => '欢迎，:name',
 *   ], 'zh-CN');
 *   Translator::instance()->setLocale('en');
 */
class Translator
{
    protected static ?self $instance = null;

    /** @var array<string,array<string,string>> locale => (key => text) */
    protected array $lines = [];

    protected string $locale = 'zh-CN';

    protected string $fallback = 'zh-CN';

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setFallback(string $locale): self
    {
        $this->fallback = $locale;
        return $this;
    }

    /**
     * 注入文案（覆盖同 key）。$lines 可为扁平 [key => text]；
     * 也支持嵌套数组（按点号展开为 group.key）。
     *
     * @param array<string,mixed> $lines
     */
    public function addLines(array $lines, string $locale): self
    {
        foreach ($this->flatten($lines) as $k => $v) {
            $this->lines[$locale][$k] = is_string($v) ? $v : (string) $v;
        }
        return $this;
    }

    /**
     * 取词：先当前语言，再 fallback，都没有则返回原 key（并做占位符替换）。
     *
     * @param array<string,string> $replace
     */
    public function get(string $key, array $replace = []): string
    {
        $text = $this->lines[$this->locale][$key]
            ?? $this->lines[$this->fallback][$key]
            ?? $key;

        return $this->replacePlaceholders($text, $replace);
    }

    /**
     * 复数选择取词：按 $count 从文案的多段形态中挑出匹配的一段。
     *
     * 文案形态（段间以 `|` 分隔，条件可包在 `{...}` 或 `[...]` 中）：
     *   - 带条件：'{0} 无|[1,4] 少量|[5,*] 大量' 或 '{0} 无|{1,4} 少量|{5,*} 大量'
     *             （条件支持 0 / 1,3 / 5,* / *,10 / *）
     *   - 不带条件且恰两段：'单数|复数'（count==1 取前段，否则取后段）
     *
     * 选中段再做占位符替换；`:count` 永远被替换为当前计数，便于直接写“共 :count 条”。
     *
     * @param array<string,string> $replace
     */
    public function choice(string $key, int $count, array $replace = []): string
    {
        $line = $this->lines[$this->locale][$key]
            ?? $this->lines[$this->fallback][$key]
            ?? $key;

        $text = $this->parseChoice($line, $count);
        if ($text === null) {
            // 无 {条件} 形态的降级：恰两段按单复数，否则整体当作文案
            $parts = explode('|', $line);
            if (count($parts) === 2) {
                $text = $count === 1 ? $parts[0] : $parts[1];
            } else {
                $text = $line;
            }
        }
        // 注意：replacePlaceholders() 会自动给键加 “:” 前缀，故此处用 “count”（不带冒号），
        // 以便文案中的 :count 占位符被替换为当前计数。
        return $this->replacePlaceholders($text, array_merge($replace, ['count' => (string) $count]));
    }

    /**
     * 解析复数选择文案，返回匹配 $count 的那一段；无任何 {条件}/[条件] 段时返回 null（交由调用方降级）。
     */
    protected function parseChoice(string $line, int $count): ?string
    {
        if (!preg_match('/[\{\[]/', $line)) {
            return null;
        }
        $catchAll = null;
        foreach (preg_split('/\s*\|\s*/', $line) ?: [] as $seg) {
            if (preg_match('/^(?:\{([^}]*)\}|\[([^\]]*)\])\s*(.*)$/s', $seg, $m)) {
                $cond = $m[1] !== '' ? $m[1] : $m[2];
                if ($this->matchCondition(trim($cond), $count)) {
                    return $m[3];
                }
            } else {
                $catchAll = $seg;
            }
        }
        return $catchAll;
    }

    /**
     * 条件匹配：'*' 任意；'3' 精确；'1,3' 闭区间；'5,*' 下界；'*,10' 上界。
     */
    protected function matchCondition(string $cond, int $count): bool
    {
        if ($cond === '*') {
            return true;
        }
        if (str_contains($cond, ',')) {
            [$lo, $hi] = array_map('trim', explode(',', $cond, 2));
            $lo = $lo === '*' || $lo === '' ? null : (int) $lo;
            $hi = $hi === '*' || $hi === '' ? null : (int) $hi;
            if ($lo !== null && $count < $lo) {
                return false;
            }
            if ($hi !== null && $count > $hi) {
                return false;
            }
            return true;
        }
        return $count === (int) $cond;
    }

    /**
     * 把 :key 占位符替换为给定值（:count 等由调用方一并传入）。
     *
     * @param array<string,string> $replace
     */
    protected function replacePlaceholders(string $text, array $replace): string
    {
        if ($replace === []) {
            return $text;
        }
        return str_replace(
            array_map(fn ($k): string => ':' . $k, array_keys($replace)),
            array_values($replace),
            $text
        );
    }

    /**
     * 把嵌套数组展开为点号键（[ 'user' => ['name' => 'x'] ] => ['user.name' => 'x']）
     *
     * @param array<string,mixed> $arr
     * @return array<string,string>
     */
    protected function flatten(array $arr, string $prefix = ''): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v)) {
                $out += $this->flatten($v, $key);
            } else {
                $out[$key] = (string) $v;
            }
        }
        return $out;
    }
}
