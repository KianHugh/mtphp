<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Schema;

/**
 * 表蓝图（Schema 构造器）
 *
 * 设计目标：用链式、可读的方式描述一张表的结构（列 + 索引 + 外键 + 改名/删除），
 * 由 Grammar 编译为对应数据库方言的 SQL。思想借鉴主流框架的 Schema 构造器，
 * 但此处为零依赖、独立实现。
 *
 * 用法：
 *   Schema::create('users', function (Blueprint $t) {
 *       $t->id();
 *       $t->string('name', 100)->nullable(false);
 *       $t->integer('age')->unsigned()->default(0);
 *       $t->timestamps();
 *       $t->unique('name');
 *   });
 */
class Blueprint
{
    public string $table;

    /** @var Column[] */
    public array $columns = [];

    /** @var array<int,array{type:string,columns:list<string>,name:?string,fk?:ForeignKey}> */
    public array $commands = [];

    public bool $dropIfExists = false;

    public ?string $renameTo = null;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    // ---------------- 列 ----------------

    /**
     * 自增主键（默认列名 id；MySQL 为 INT UNSIGNED AUTO_INCREMENT，SQLite 为 INTEGER AUTOINCREMENT）
     */
    public function increments(string $col = 'id'): Column
    {
        return $this->addColumn($col, 'increments')->autoIncrement()->primary();
    }

    /**
     * 同 increments，语义化别名
     */
    public function id(string $col = 'id'): Column
    {
        return $this->increments($col);
    }

    public function string(string $col, int $len = 255): Column
    {
        $c        = $this->addColumn($col, 'string');
        $c->length = $len;
        return $c;
    }

    public function char(string $col, int $len = 255): Column
    {
        $c        = $this->addColumn($col, 'char');
        $c->length = $len;
        return $c;
    }

    public function text(string $col): Column
    {
        return $this->addColumn($col, 'text');
    }

    public function integer(string $col): Column
    {
        return $this->addColumn($col, 'integer');
    }

    public function tinyInteger(string $col): Column
    {
        return $this->addColumn($col, 'tinyInteger');
    }

    public function bigInteger(string $col): Column
    {
        return $this->addColumn($col, 'bigInteger');
    }

    public function boolean(string $col): Column
    {
        return $this->addColumn($col, 'boolean');
    }

    public function float(string $col): Column
    {
        return $this->addColumn($col, 'float');
    }

    public function decimal(string $col, int $precision = 8, int $scale = 2): Column
    {
        $c         = $this->addColumn($col, 'decimal');
        $c->length = $precision;
        $c->scale  = $scale;
        return $c;
    }

    public function date(string $col): Column
    {
        return $this->addColumn($col, 'date');
    }

    public function dateTime(string $col): Column
    {
        return $this->addColumn($col, 'dateTime');
    }

    public function time(string $col): Column
    {
        return $this->addColumn($col, 'time');
    }

    public function timestamp(string $col): Column
    {
        return $this->addColumn($col, 'timestamp');
    }

    public function json(string $col): Column
    {
        return $this->addColumn($col, 'json');
    }

    /**
     * 枚举（MySQL 原生 ENUM；SQLite 退化为 TEXT，保证可移植）
     *
     * @param array<int,string> $values
     */
    public function enum(string $col, array $values): Column
    {
        $c              = $this->addColumn($col, 'enum');
        $c->enumValues  = array_values($values);
        return $c;
    }

    /**
     * created_at / updated_at 两个可空时间戳
     */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    /**
     * deleted_at 可空时间戳（软删除标记列）
     */
    public function softDeletes(): void
    {
        $this->timestamp('deleted_at')->nullable();
    }

    protected function addColumn(string $name, string $type): Column
    {
        $c            = new Column($name, $type);
        $this->columns[] = $c;
        return $c;
    }

    // ---------------- 表级命令 ----------------

    /**
     * @param string|list<string> $columns
     */
    public function primary(string|array $columns, ?string $name = null): self
    {
        $this->commands[] = [
            'type'    => 'primary',
            'columns' => (array) $columns,
            'name'    => $name,
        ];
        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $this->commands[] = [
            'type'    => 'unique',
            'columns' => (array) $columns,
            'name'    => $name,
        ];
        return $this;
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): self
    {
        $this->commands[] = [
            'type'    => 'index',
            'columns' => (array) $columns,
            'name'    => $name,
        ];
        return $this;
    }

    public function foreign(string $column): ForeignKey
    {
        $fk               = new ForeignKey($column);
        $this->commands[] = ['type' => 'foreign', 'fk' => $fk];
        return $fk;
    }

    /**
     * @param string|list<string> $columns
     */
    public function dropColumn(string|array $columns): self
    {
        $this->commands[] = [
            'type'    => 'dropColumn',
            'columns' => (array) $columns,
        ];
        return $this;
    }

    public function dropIndex(string $name): self
    {
        $this->commands[] = ['type' => 'dropIndex', 'name' => $name];
        return $this;
    }

    public function dropForeign(string $name): self
    {
        $this->commands[] = ['type' => 'dropForeign', 'name' => $name];
        return $this;
    }

    public function dropIfExists(): self
    {
        $this->dropIfExists = true;
        return $this;
    }

    public function rename(string $to): self
    {
        $this->renameTo = $to;
        return $this;
    }
}
