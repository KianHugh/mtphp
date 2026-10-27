<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Schema;

/**
 * 外键定义（Blueprint::foreign() 返回，可链式 references / on / onDelete）
 */
class ForeignKey
{
    public string $column;

    public ?string $references = null;

    public ?string $on = null;

    public string $name;

    public string $onDelete = '';

    public function __construct(string $column)
    {
        $this->column = $column;
        $this->name   = 'fk_' . $column;
    }

    public function references(string $col): self
    {
        $this->references = $col;
        return $this;
    }

    public function on(string $table): self
    {
        $this->on = $table;
        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = $action;
        return $this;
    }
}
