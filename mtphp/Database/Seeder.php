<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database;

/**
 * 数据填充（Seeder）基类
 *
 * 用法：继承本类并实现 run()，在 run() 里用 DB::table() 或 Model 写入初始数据。
 * 通过 call() 调用其它 Seeder，实现“主填充 → 子填充”的层级组织。
 *
 *   class DatabaseSeeder extends Seeder {
 *       public function run(): void {
 *           $this->call(UserSeeder::class);
 *           $this->call(PostSeeder::class);
 *       }
 *   }
 */
abstract class Seeder
{
    /**
     * 执行本填充的具体逻辑（子类实现）
     */
    abstract public function run(): void;

    /**
     * 调用另一个 Seeder（支持层级组织）
     *
     * @param class-string<Seeder> $class
     */
    public function call(string $class): void
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("Seeder 类不存在：{$class}");
        }
        $instance = new $class();
        if (!$instance instanceof self) {
            throw new \RuntimeException("{$class} 必须继承 " . self::class);
        }
        $instance->run();
    }
}
