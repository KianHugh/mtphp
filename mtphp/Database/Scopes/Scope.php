<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Database\Scopes;

use Mtphp\Database\ModelBuilder;

/**
 * 全局作用域契约（零依赖，思想借鉴主流框架的 Scope 接口）
 *
 * 实现后通过 Model::addGlobalScope() 注册，框架在每次查询编译前自动 apply，
 * 除非调用者显式 withoutGlobalScope() / withoutGlobalScopes() 移除。
 *
 * 例：
 *   class PublishedScope implements Scope
 *   {
 *       public function apply(ModelBuilder $builder): void
 *       {
 *           $builder->where('published', 1);
 *       }
 *   }
 *   Post::addGlobalScope(new PublishedScope());
 */
interface Scope
{
    public function apply(ModelBuilder $builder): void;
}
