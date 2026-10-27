<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Schema;

/**
 * 列定义（Schema 构造器内部使用）
 *
 * 由 Blueprint 的 string() / integer() 等列方法创建，并可链式追加修饰符
 * （nullable / default / unsigned / unique / primary / comment …）。
 */
class Column
{
    public string $name;

    public string $type;

    public int $length = 0;

    public int $scale = 0;

    public bool $nullable = false;

    public bool $unsigned = false;

    public mixed $default = null;

    public bool $hasDefault = false;

    public bool $autoIncrement = false;

    public bool $primary = false;

    public bool $unique = false;

    /** @var array<int,string> */
    public array $enumValues = [];

    public string $comment = '';

    public function __construct(string $name, string $type)
    {
        $this->name  = $name;
        $this->type  = $type;
    }

    public function nullable(bool $v = true): self
    {
        $this->nullable = $v;
        return $this;
    }

    public function unsigned(bool $v = true): self
    {
        $this->unsigned = $v;
        return $this;
    }

    public function default(mixed $v): self
    {
        $this->default    = $v;
        $this->hasDefault = true;
        return $this;
    }

    public function autoIncrement(bool $v = true): self
    {
        $this->autoIncrement = $v;
        return $this;
    }

    public function primary(bool $v = true): self
    {
        $this->primary = $v;
        return $this;
    }

    public function unique(bool $v = true): self
    {
        $this->unique = $v;
        return $this;
    }

    public function comment(string $v): self
    {
        $this->comment = $v;
        return $this;
    }
}
