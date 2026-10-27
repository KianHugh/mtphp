<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Schema;

/**
 * Schema 语法编译器（零依赖，独立实现）
 *
 * 把 Blueprint 编译成对应数据库方言的 SQL 语句数组。目前支持 mysql 与 sqlite 两种
 * 方言，差异点主要落在：自增主键写法、JSON/布尔/枚举类型的映射、外键/索引语句形态。
 */
class Grammar
{
    /**
     * 编译建表语句（含列、表级主键、内联外键）
     *
     * @return list<string>
     */
    public function compileCreate(Blueprint $bp, string $driver): array
    {
        $lines = [];

        foreach ($bp->columns as $col) {
            $lines[] = $this->compileColumn($col, $driver);
        }

        // 表级主键（列自身未声明 primary 时）
        foreach ($bp->commands as $cmd) {
            if ($cmd['type'] === 'primary') {
                $cols    = implode(', ', array_map(fn ($c) => "`$c`", $cmd['columns']));
                $lines[] = 'PRIMARY KEY (' . $cols . ')';
            }
        }

        // 内联外键
        foreach ($bp->commands as $cmd) {
            if ($cmd['type'] === 'foreign') {
                $lines[] = $this->compileForeignInline($cmd['fk'], $driver);
            }
        }

        $sql = 'CREATE TABLE `' . $bp->table . '` (' . implode(', ', $lines) . ')';

        $statements = [$sql];

        // 索引（唯一/普通）单独建
        foreach ($bp->commands as $cmd) {
            if ($cmd['type'] === 'unique' || $cmd['type'] === 'index') {
                $statements[] = $this->compileIndex($bp->table, $cmd, $driver);
            }
        }

        return $statements;
    }

    /**
     * 编译修改表语句（加列 / 加索引 / 加外键 / 删列 / 删索引 / 删外键 / 改名）
     *
     * @return list<string>
     */
    public function compileAlter(Blueprint $bp, string $driver): array
    {
        $statements = [];

        if ($bp->renameTo !== null) {
            $statements[] = $driver === 'sqlite'
                ? 'ALTER TABLE `' . $bp->table . '` RENAME TO `' . $bp->renameTo . '`'
                : 'RENAME TABLE `' . $bp->table . '` TO `' . $bp->renameTo . '`';
        }

        foreach ($bp->columns as $col) {
            $statements[] = 'ALTER TABLE `' . $bp->table . '` ADD COLUMN ' . $this->compileColumn($col, $driver);
        }

        foreach ($bp->commands as $cmd) {
            if ($cmd['type'] === 'unique' || $cmd['type'] === 'index') {
                $statements[] = $this->compileIndex($bp->table, $cmd, $driver);
            } elseif ($cmd['type'] === 'foreign') {
                $statements[] = $this->compileAddForeign($bp->table, $cmd['fk'], $driver);
            } elseif ($cmd['type'] === 'dropColumn') {
                foreach ($cmd['columns'] as $c) {
                    $statements[] = 'ALTER TABLE `' . $bp->table . '` DROP COLUMN `' . $c . '`';
                }
            } elseif ($cmd['type'] === 'dropIndex') {
                $statements[] = $this->compileDropIndex($cmd['name'], $driver);
            } elseif ($cmd['type'] === 'dropForeign') {
                $statements[] = $this->compileDropForeign($bp->table, $cmd['name'], $driver);
            }
        }

        return $statements;
    }

    public function compileDrop(string $table, bool $ifExists, string $driver): string
    {
        return 'DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . '`' . $table . '`';
    }

    // ---------------- 内部：列 ----------------

    protected function compileColumn(Column $col, string $driver): string
    {
        // 自增主键：两种方言写法完全不同，单独处理
        if ($col->autoIncrement) {
            if ($driver === 'sqlite') {
                return '`' . $col->name . '` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT';
            }
            $type = $col->unsigned ? 'INT UNSIGNED' : 'INT';
            return '`' . $col->name . '` ' . $type . ' NOT NULL AUTO_INCREMENT PRIMARY KEY';
        }

        $type = $this->mapType($col, $driver);
        $sql  = '`' . $col->name . '` ' . $type;

        if ($col->unsigned && $driver === 'mysql' && in_array($col->type, ['integer', 'bigInteger', 'tinyInteger', 'decimal', 'float'], true)) {
            $sql .= ' UNSIGNED';
        }

        $sql .= $col->nullable ? ' NULL' : ' NOT NULL';

        if ($col->hasDefault) {
            $sql .= ' DEFAULT ' . $this->compileDefault($col->default, $driver);
        }

        if ($col->unique && $driver === 'mysql') {
            $sql .= ' UNIQUE';
        }

        if ($col->comment !== '' && $driver === 'mysql') {
            $sql .= " COMMENT '" . str_replace("'", "''", $col->comment) . "'";
        }

        return $sql;
    }

    protected function mapType(Column $col, string $driver): string
    {
        switch ($col->type) {
            case 'string':
            case 'char':
                return 'VARCHAR(' . ($col->length ?: 255) . ')';
            case 'text':
                return 'TEXT';
            case 'integer':
                return $driver === 'sqlite' ? 'INTEGER' : 'INT';
            case 'tinyInteger':
                return $driver === 'sqlite' ? 'INTEGER' : 'TINYINT';
            case 'bigInteger':
                return $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
            case 'boolean':
                return $driver === 'sqlite' ? 'INTEGER' : 'TINYINT(1)';
            case 'float':
                return $driver === 'sqlite' ? 'REAL' : 'DOUBLE';
            case 'decimal':
                return $driver === 'sqlite' ? 'REAL' : 'DECIMAL(' . ($col->length ?: 8) . ',' . ($col->scale ?: 2) . ')';
            case 'date':
                return 'DATE';
            case 'dateTime':
            case 'timestamp':
                return $driver === 'sqlite' ? 'DATETIME' : 'DATETIME';
            case 'time':
                return 'TIME';
            case 'json':
                return $driver === 'sqlite' ? 'TEXT' : 'JSON';
            case 'enum':
                if ($driver === 'sqlite') {
                    return 'TEXT';
                }
                $vals = implode(', ', array_map(fn ($v) => "'" . str_replace("'", "''", (string) $v) . "'", $col->enumValues));
                return 'ENUM(' . $vals . ')';
            default:
                return 'TEXT';
        }
    }

    protected function compileDefault(mixed $value, string $driver): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return "'" . str_replace("'", "''", json_encode($value, JSON_UNESCAPED_UNICODE)) . "'";
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    // ---------------- 内部：索引 / 外键 ----------------

    /**
     * @param array{type:string,columns:list<string>,name:?string} $cmd
     */
    protected function compileIndex(string $table, array $cmd, string $driver): string
    {
        $name   = $cmd['name'] ?? ('ix_' . $table . '_' . implode('_', $cmd['columns']));
        $cols   = implode(', ', array_map(fn ($c) => "`$c`", $cmd['columns']));
        $unique = $cmd['type'] === 'unique' ? 'UNIQUE ' : '';
        return 'CREATE ' . $unique . 'INDEX `' . $name . '` ON `' . $table . '` (' . $cols . ')';
    }

    protected function compileForeignInline(ForeignKey $fk, string $driver): string
    {
        $sql = 'CONSTRAINT `' . $fk->name . '` FOREIGN KEY (`' . $fk->column . '`)'
             . ' REFERENCES `' . $fk->on . '` (`' . $fk->references . '`)';
        if ($fk->onDelete !== '') {
            $sql .= ' ON DELETE ' . $fk->onDelete;
        }
        return $sql;
    }

    protected function compileAddForeign(string $table, ForeignKey $fk, string $driver): string
    {
        $sql = 'ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $fk->name . '`'
             . ' FOREIGN KEY (`' . $fk->column . '`) REFERENCES `' . $fk->on . '` (`' . $fk->references . '`)';
        if ($fk->onDelete !== '') {
            $sql .= ' ON DELETE ' . $fk->onDelete;
        }
        return $sql;
    }

    protected function compileDropIndex(string $name, string $driver): string
    {
        return $driver === 'sqlite'
            ? 'DROP INDEX IF EXISTS `' . $name . '`'
            : 'DROP INDEX `' . $name . '`';
    }

    protected function compileDropForeign(string $table, string $name, string $driver): string
    {
        return $driver === 'sqlite'
            ? 'ALTER TABLE `' . $table . '` DROP CONSTRAINT `' . $name . '`'
            : 'ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . $name . '`';
    }
}
