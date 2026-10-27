<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Validation;

use Mtphp\Database\DB;

/**
 * 轻量表单校验器（零依赖，思想借鉴主流框架的 Validation 组件）
 *
 * 设计目标：用最简单的方式完成最常见的“入参校验”，支持字符串/数组两种规则写法，
 * 自带常用规则，并支持自定义扩展。全部规则均防注入（仅做值判断，不拼接 SQL）。
 *
 * 用法：
 *   $v = Validator::make($input, [
 *       'name'   => 'required|max:50',
 *       'email'  => 'required|email',
 *       'age'    => 'nullable|integer|min:0|max:150',
 *       'status' => 'required|in:0,1,2',
 *   ]);
 *   if ($v->fails()) {
 *       return $v->errors();   // ['name' => ['name 必填', ...], ...]
 *   }
 *   $data = $v->validated();   // 通过校验的字段集合
 *
 * 规则写法：
 *   - 字符串：'required|email|max:255'（竖线分隔，冒号带参）
 *   - 数组：['required', 'email', 'max:255']
 *   - 闭包（自定义）：fn ($value, array $params) => bool
 *
 * 占位符：错误信息支持 :field（字段名）、:param（参数）、:value（实际值）。
 */
class Validator
{
    /** @var array<string,mixed> 被校验的数据 */
    protected array $data;

    /** @var array<string,string|array<int,string|callable>> 规则 */
    protected array $rules;

    /** @var array<string,string> 自定义错误信息（字段.规则 => 文案） */
    protected array $messages;

    /** @var array<string,list<string>> 错误集合 */
    protected array $errors = [];

    /** @var array<string,mixed> 通过校验的字段 */
    protected array $validated = [];

    /** @var array<string,callable(string,mixed,array):bool> 扩展规则 */
    protected static array $extensions = [];

    public function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data     = $data;
        $this->rules    = $rules;
        $this->messages = $messages;
    }

    /**
     * 工厂方法
     */
    public static function make(array $data, array $rules, array $messages = []): self
    {
        return new self($data, $rules, $messages);
    }

    /**
     * 注册自定义规则（全局生效）
     *
     * @param callable(string $value, array $params):bool $callback
     */
    public static function extend(string $rule, callable $callback): void
    {
        self::$extensions[$rule] = $callback;
    }

    /**
     * 执行校验，返回是否通过
     */
    public function validate(): bool
    {
        $this->errors    = [];
        $this->validated = [];

        foreach ($this->rules as $field => $ruleDef) {
            $rules   = $this->parseRules($ruleDef);
            $value   = $this->data[$field] ?? null;
            $isEmpty = $value === null || $value === '';

            // nullable：值为空时跳过其余规则（含自身之外的所有）
            $hasNullable = false;
            foreach ($rules as [$name]) {
                if ($name === 'nullable') {
                    $hasNullable = true;
                    break;
                }
            }

            if ($isEmpty && $hasNullable) {
                $this->validated[$field] = $value;
                continue;
            }

            $failed = false;
            foreach ($rules as [$name, $params]) {
                if ($name === 'nullable') {
                    continue;
                }
                if (!$this->applyRule($name, $field, $value, $params)) {
                    $this->addError($field, $name, $params, $value);
                    $failed = true;
                    break; // 一个字段取首个失败规则的错误即可
                }
            }

            if (!$failed) {
                $this->validated[$field] = $value;
            }
        }

        return empty($this->errors);
    }

    /**
     * 是否校验失败
     */
    public function fails(): bool
    {
        return !$this->validate();
    }

    /**
     * 错误信息（字段 => 错误数组）
     *
     * @return array<string,list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * 首条错误信息（用于快速返回）
     */
    public function firstError(): ?string
    {
        foreach ($this->errors as $list) {
            if ($list !== []) {
                return $list[0];
            }
        }
        return null;
    }

    /**
     * 通过校验的字段集合
     *
     * @return array<string,mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    // ------------------------------------------------------------------
    // 规则解析与分发
    // ------------------------------------------------------------------

    /**
     * @param string|array<int,string|callable> $def
     * @return list<array{0:string,1:array<int,string>}>
     */
    protected function parseRules($def): array
    {
        if (is_string($def)) {
            $def = explode('|', $def);
        }
        $out = [];
        foreach ($def as $item) {
            if (is_callable($item) && !is_string($item)) {
                $out[] = ['callback', [$item]];
                continue;
            }
            $item = (string) $item;
            if (strpos($item, ':') !== false) {
                [$name, $paramStr] = explode(':', $item, 2);
                $params = $paramStr === '' ? [] : explode(',', $paramStr);
            } else {
                $name   = $item;
                $params = [];
            }
            $out[] = [trim($name), $params];
        }
        return $out;
    }

    /**
     * 应用单条规则
     *
     * @param array<int,string> $params
     */
    protected function applyRule(string $name, string $field, mixed $value, array $params): bool
    {
        // 闭包 / 扩展规则
        if ($name === 'callback' && isset($params[0]) && is_callable($params[0])) {
            return (bool) ($params[0])($value, []);
        }
        if (isset(self::$extensions[$name])) {
            return (bool) (self::$extensions[$name])($value, $params);
        }

        // 内置规则统一走 ruleXxx 方法（第三个参数为字段名，供 confirmed 等使用）
        $method = 'rule' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
        if (!method_exists($this, $method)) {
            throw new \RuntimeException("未知校验规则：{$name}");
        }
        return $this->$method($value, $params, $field);
    }

    /**
     * 记录错误（优先用自定义文案，否则内置模板）
     *
     * @param array<int,string> $params
     */
    protected function addError(string $field, string $rule, array $params, mixed $value): void
    {
        $key  = $field . '.' . $rule;
        $tpl  = $this->messages[$key]
            ?? $this->messages[$field]
            ?? $this->defaultMessage($rule);
        $msg  = str_replace(
            [':field', ':param', ':value'],
            [$field, implode(',', $params), is_scalar($value) ? (string) $value : ''],
            $tpl
        );
        $this->errors[$field][] = $msg;
    }

    /**
     * 内置错误模板
     */
    protected function defaultMessage(string $rule): string
    {
        return [
            'required'    => ':field 为必填项',
            'required_if' => ':field 在满足条件时为必填项',
            'email'      => ':field 必须是合法邮箱',
            'numeric'    => ':field 必须是数字',
            'integer'    => ':field 必须是整数',
            'min'        => ':field 不能小于 :param',
            'max'        => ':field 不能大于 :param',
            'between'    => ':field 必须在 :param 之间',
            'length'     => ':field 长度必须为 :param',
            'in'         => ':field 取值超出允许范围',
            'not_in'     => ':field 取值不被允许',
            'regex'      => ':field 格式不正确',
            'confirmed'  => ':field 确认不一致',
            'same'       => ':field 必须与 :param 一致',
            'alpha'      => ':field 只能包含字母',
            'alpha_num'  => ':field 只能包含字母和数字',
            'url'        => ':field 必须是合法 URL',
            'ip'         => ':field 必须是合法 IP',
            'date'       => ':field 必须是合法日期',
            'unique'     => ':field 已存在',
            'exists'     => ':field 不存在',
            'boolean'    => ':field 必须是布尔值',
            'digits'     => ':field 必须是 :param 位数字',
            'digits_between' => ':field 位数必须在 :param 之间',
            'array'      => ':field 必须是数组',
            'json'       => ':field 必须是合法 JSON',
            'starts_with' => ':field 格式不正确',
            'ends_with'  => ':field 格式不正确',
            'different'  => ':field 不能与 :param 相同',
            'before'     => ':field 必须早于指定时间',
            'after'      => ':field 必须晚于指定时间',
            'size'       => ':field 大小必须为 :param',
            'accepted'   => ':field 必须被接受',
            'mobile_cn'  => ':field 必须是中国大陆手机号',
            'id_card'    => ':field 必须是合法身份证号',
            'credit_code' => ':field 必须是合法统一社会信用代码',
        ][$rule] ?? (':field 校验未通过（' . $rule . '）');
    }

    // ------------------------------------------------------------------
    // 内置规则实现（统一签名：($value, $params) => bool）
    // ------------------------------------------------------------------

    protected function ruleRequired(mixed $value, array $params): bool
    {
        return $value !== null && $value !== '';
    }

    protected function ruleRequiredIf(mixed $value, array $params): bool
    {
        // required_if:other,val —— 当 other 字段等于 val 时本字段必填
        if (count($params) < 2) {
            return true;
        }
        $other = $params[0];
        $expect = $params[1];
        $otherVal = $this->data[$other] ?? null;
        if ((string) $otherVal === (string) $expect) {
            return $value !== null && $value !== '';
        }
        return true;
    }

    protected function ruleEmail(mixed $value, array $params): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function ruleNumeric(mixed $value, array $params): bool
    {
        return is_numeric($value);
    }

    protected function ruleInteger(mixed $value, array $params): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    protected function ruleMin(mixed $value, array $params): bool
    {
        $min = (float) ($params[0] ?? 0);
        if (is_numeric($value)) {
            return (float) $value >= $min;
        }
        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }
        return false;
    }

    protected function ruleMax(mixed $value, array $params): bool
    {
        $max = (float) ($params[0] ?? 0);
        if (is_numeric($value)) {
            return (float) $value <= $max;
        }
        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }
        return false;
    }

    protected function ruleBetween(mixed $value, array $params): bool
    {
        if (count($params) < 2) {
            return false;
        }
        $lo = (float) $params[0];
        $hi = (float) $params[1];
        if (!is_numeric($value)) {
            return false;
        }
        $v = (float) $value;
        return $v >= $lo && $v <= $hi;
    }

    protected function ruleLength(mixed $value, array $params): bool
    {
        if (!is_string($value) || !isset($params[0])) {
            return false;
        }
        return mb_strlen($value) === (int) $params[0];
    }

    protected function ruleIn(mixed $value, array $params): bool
    {
        return in_array((string) $value, $params, true);
    }

    protected function ruleNotIn(mixed $value, array $params): bool
    {
        return !in_array((string) $value, $params, true);
    }

    protected function ruleRegex(mixed $value, array $params): bool
    {
        if (!isset($params[0]) || !is_string($value)) {
            return false;
        }
        return @preg_match($params[0], $value) === 1;
    }

    protected function ruleConfirmed(mixed $value, array $params, string $field = ''): bool
    {
        // 约定：被确认字段名为 {field}_confirmation（如 password => password_confirmation）
        $confirmField = $field . '_confirmation';
        return isset($this->data[$confirmField]) && (string) $this->data[$confirmField] === (string) $value;
    }

    protected function ruleSame(mixed $value, array $params): bool
    {
        if (!isset($params[0])) {
            return false;
        }
        $other = $this->data[$params[0]] ?? null;
        return (string) $other === (string) $value;
    }

    protected function ruleAlpha(mixed $value, array $params): bool
    {
        return is_string($value) && ctype_alpha($value);
    }

    protected function ruleAlphaNum(mixed $value, array $params): bool
    {
        return is_string($value) && ctype_alnum($value);
    }

    protected function ruleUrl(mixed $value, array $params): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    protected function ruleIp(mixed $value, array $params): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    protected function ruleDate(mixed $value, array $params): bool
    {
        if (!is_string($value)) {
            return false;
        }
        return strtotime($value) !== false;
    }

    protected function ruleBoolean(mixed $value, array $params, string $field = ''): bool
    {
        return in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true);
    }

    protected function ruleDigits(mixed $value, array $params, string $field = ''): bool
    {
        if (!isset($params[0]) || !is_string($value)) {
            return false;
        }
        return ctype_digit($value) && mb_strlen($value) === (int) $params[0];
    }

    protected function ruleDigitsBetween(mixed $value, array $params, string $field = ''): bool
    {
        if (count($params) < 2 || !is_string($value)) {
            return false;
        }
        $len = mb_strlen($value);
        return ctype_digit($value) && $len >= (int) $params[0] && $len <= (int) $params[1];
    }

    protected function ruleArray(mixed $value, array $params, string $field = ''): bool
    {
        return is_array($value);
    }

    protected function ruleJson(mixed $value, array $params, string $field = ''): bool
    {
        if (!is_string($value)) {
            return false;
        }
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    protected function ruleStartsWith(mixed $value, array $params, string $field = ''): bool
    {
        if (!is_string($value) || $params === []) {
            return false;
        }
        foreach ($params as $p) {
            if (str_starts_with($value, $p)) {
                return true;
            }
        }
        return false;
    }

    protected function ruleEndsWith(mixed $value, array $params, string $field = ''): bool
    {
        if (!is_string($value) || $params === []) {
            return false;
        }
        foreach ($params as $p) {
            if (str_ends_with($value, $p)) {
                return true;
            }
        }
        return false;
    }

    protected function ruleDifferent(mixed $value, array $params, string $field = ''): bool
    {
        if (!isset($params[0])) {
            return false;
        }
        $other = $this->data[$params[0]] ?? null;
        return (string) $other !== (string) $value;
    }

    protected function ruleBefore(mixed $value, array $params, string $field = ''): bool
    {
        if (!isset($params[0]) || !is_string($value)) {
            return false;
        }
        $vt = strtotime($value);
        $ts = $this->toTimestamp($params[0]);
        return $vt !== false && $ts !== false && $vt < $ts;
    }

    protected function ruleAfter(mixed $value, array $params, string $field = ''): bool
    {
        if (!isset($params[0]) || !is_string($value)) {
            return false;
        }
        $vt = strtotime($value);
        $ts = $this->toTimestamp($params[0]);
        return $vt !== false && $ts !== false && $vt > $ts;
    }

    protected function ruleSize(mixed $value, array $params, string $field = ''): bool
    {
        if (!isset($params[0])) {
            return false;
        }
        $n = (int) $params[0];
        if (is_numeric($value)) {
            return (float) $value === (float) $n;
        }
        if (is_string($value)) {
            return mb_strlen($value) === $n;
        }
        if (is_array($value)) {
            return count($value) === $n;
        }
        return false;
    }

    protected function ruleAccepted(mixed $value, array $params, string $field = ''): bool
    {
        return in_array($value, ['yes', 'on', 1, '1', true], true);
    }

    /**
     * 中国大陆手机号（1[3-9] 开头共 11 位）
     */
    protected function ruleMobileCn(mixed $value, array $params, string $field = ''): bool
    {
        return is_string($value) && preg_match('/^1[3-9]\d{9}$/', $value) === 1;
    }

    /**
     * 中国大陆居民身份证（18 位，含校验位）
     */
    protected function ruleIdCard(mixed $value, array $params, string $field = ''): bool
    {
        if (!is_string($value) || preg_match('/^\d{17}[\dX]$/i', $value) !== 1) {
            return false;
        }
        $weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
        $check   = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
        $sum     = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += (int) $value[$i] * $weights[$i];
        }
        return strtoupper($value[17]) === $check[$sum % 11];
    }

    /**
     * 统一社会信用代码（18 位，GB32100，含校验位）
     */
    protected function ruleCreditCode(mixed $value, array $params, string $field = ''): bool
    {
        if (!is_string($value) || strlen($value) !== 18) {
            return false;
        }
        $chars  = '0123456789ABCDEFGHJKLMNPQRTUWXY';
        $map    = [];
        foreach (str_split($chars) as $i => $ch) {
            $map[$ch] = $i;
        }
        $weights = [1, 3, 9, 27, 19, 26, 16, 17, 20, 29, 25, 13, 8, 24, 10, 30, 28];
        $sum     = 0;
        for ($i = 0; $i < 17; $i++) {
            $ch = $value[$i];
            if (!isset($map[$ch])) {
                return false;
            }
            $sum += $map[$ch] * $weights[$i];
        }
        $mod = 31 - ($sum % 31);
        if ($mod === 31) {
            $mod = 0;
        }
        return $value[17] === $chars[$mod];
    }

    /**
     * 把 before/after 的参数解析为时间戳：数字直接当时间戳；
     * 否则尝试按日期字符串解析，再退化为“同名字段的值”。
     */
    protected function toTimestamp(string $v): int|false
    {
        if (is_numeric($v)) {
            return (int) $v;
        }
        $t = strtotime($v);
        if ($t !== false) {
            return $t;
        }
        if (isset($this->data[$v])) {
            return strtotime((string) $this->data[$v]);
        }
        return false;
    }

    /**
     * unique:table,column —— 查数据库确认值未存在（借助 DB 构造器，参数化防注入）
     */
    protected function ruleUnique(mixed $value, array $params): bool
    {
        if (!isset($params[0]) || !class_exists(DB::class)) {
            return true;
        }
        $table  = $params[0];
        $column = $params[1] ?? (string) array_search($value, $this->data, true);
        try {
            $count = DB::table($table)->where($column, '=', $value)->count();
            return $count === 0;
        } catch (\Throwable $e) {
            // 无数据库上下文时跳过（不参与校验）
            return true;
        }
    }

    /**
     * 存在性校验（数据库规则）：值必须在指定表的列中存在。
     *
     * 用法：'email' => 'exists:users,email'（列缺省时取字段名）。
     * 空值（null / ''）不参与校验（与 required 配合，可选字段留空即放行）。
     */
    protected function ruleExists(mixed $value, array $params, string $field = ''): bool
    {
        if (!isset($params[0]) || !class_exists(DB::class)) {
            return true;
        }
        if ($value === null || $value === '') {
            return true;
        }
        $table  = $params[0];
        $column = $params[1] ?? $field;
        if ($column === '') {
            return true;
        }
        try {
            $count = DB::table($table)->where($column, '=', $value)->count();
            return $count > 0;
        } catch (\Throwable $e) {
            // 无数据库上下文时跳过（不参与校验）
            return true;
        }
    }
}
