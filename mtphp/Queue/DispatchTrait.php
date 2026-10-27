<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Queue;

use Mtphp\App;

/**
 * 类任务分发复用逻辑（文件驱动与数据库驱动共用）
 *
 * payload 为 ['class'=>类名,'data'=>任意] 时，经容器解析实例后调用其 handle($data)。
 */
trait DispatchTrait
{
    /**
     * 内置“类任务”分发
     *
     * @return bool 是否成功识别并分发了一个类任务
     */
    public function dispatch(array $job): bool
    {
        $payload = $job['payload'] ?? null;
        if (!is_array($payload) || empty($payload['class']) || !is_string($payload['class'])) {
            return false;
        }
        $class = $payload['class'];
        if (!class_exists($class) || !method_exists($class, 'handle')) {
            return false;
        }
        $instance = $this->resolveClass($class);
        $instance->handle($payload['data'] ?? []);
        return true;
    }

    protected function resolveClass(string $class): object
    {
        $app = App::instance();
        if ($app !== null) {
            return $app->container()->make($class);
        }
        return new $class();
    }
}
