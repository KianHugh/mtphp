<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Events;

use Mtphp\App;
use Mtphp\Container;

/**
 * 轻量事件分发器（零依赖，思想借鉴主流框架的事件系统）
 *
 * 支持三种监听器：
 *   - 闭包：function (OrderPaid $event) { ... }
 *   - [类名, 方法]：经容器解析实例后调用（如 [App\Listeners\Notify::class, 'handle']）
 *   - 类名字符串：经容器解析后调用其 handle($event) 方法
 *
 * 用法：
 *   event()->listen(OrderPaid::class, [App\Listeners\Notify::class, 'handle']);
 *   event(new OrderPaid($order));   // 派发；监听器可就地修改事件对象
 */
class Dispatcher
{
    protected static ?self $instance = null;

    /** @var array<string,list<array{listener:mixed,priority:int}>> 事件名 => 监听器列表（含优先级） */
    protected array $listeners = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 注册监听器（$events 可为事件类名字符串或数组）
     *
     * 优先级：数值越大越先执行（默认 0）；同优先级按注册先后。
     * 支持通配键：
     *   '*'              → 监听全部事件（全局兜底）
     *   'App\Events\*'   → 监听该命名空间下的全部事件
     *
     * @param string|list<string> $events
     * @param callable|array{0:string,1:string}|string $listener
     */
    public function listen($events, $listener, int $priority = 0): void
    {
        foreach ((array) $events as $event) {
            $this->listeners[$event][] = [
                'listener' => $listener,
                'priority' => $priority,
            ];
        }
    }

    /**
     * 注销某事件的全部监听器
     *
     * @param string|list<string> $events
     */
    public function forget($events): void
    {
        foreach ((array) $events as $event) {
            unset($this->listeners[$event]);
        }
    }

    /**
     * 清空全部已注册的监听器（通常用于测试隔离）。
     */
    public function flush(): void
    {
        $this->listeners = [];
    }

    /**
     * 订阅一组事件（批注册）。
     *
     * 两种形态：
     *   - 数组：[事件类名 => 监听器]，逐条 listen（监听器可为闭包 / [类,方法] / 类字符串）；
     *   - 订阅者对象或类名：须含 subscribe(Dispatcher $events) 方法，由其在内部自行 listen。
     *
     * @param string|array<string,mixed>|object $subscriber
     */
    public function subscribe(string|array|object $subscriber): void
    {
        if (is_array($subscriber)) {
            foreach ($subscriber as $event => $listener) {
                $this->listen($event, $listener);
            }
            return;
        }

        if (is_string($subscriber) && class_exists($subscriber)) {
            $subscriber = $this->resolveClass($subscriber);
        }

        if (is_object($subscriber) && method_exists($subscriber, 'subscribe')) {
            $subscriber->subscribe($this);
            return;
        }

        throw new \RuntimeException('事件订阅者须为 [事件 => 监听器] 数组，或含 subscribe() 方法的对象/类名');
    }

    /**
     * 派发事件；按优先级（高→低）调用监听器，返回（可能被监听器就地修改过的）事件对象
     *
     * 匹配规则：
     *   - 具体类名、其全部父类 / 接口；
     *   - 通配键 '*'（全部事件）；
     *   - 命名空间通配 'App\Events\*'（前缀匹配）。
     *
     * @param array<int,mixed> $payload 预留：类监听器可附加的额外参数
     */
    public function dispatch(object $event, array $payload = []): object
    {
        $class = get_class($event);

        // 既匹配具体类名，也匹配其全部父类/接口（便于基类事件统一监听）
        $keys = array_merge([$class], class_parents($event), class_implements($event));

        // 收集所有命中的监听器条目（含通配）
        $entries = [];
        foreach ($keys as $key) {
            if (!empty($this->listeners[$key])) {
                array_push($entries, ...$this->listeners[$key]);
            }
        }
        foreach ($this->listeners as $wildKey => $list) {
            if ($wildKey === '*') {
                array_push($entries, ...$list);
            } elseif (str_ends_with($wildKey, '\\*')) {
                $prefix = substr($wildKey, 0, -1);
                if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                    array_push($entries, ...$list);
                }
            }
        }

        // 按优先级降序；同优先级保持注册顺序（稳定排序）
        usort($entries, static fn ($a, $b) => $b['priority'] <=> $a['priority']);

        foreach ($entries as $entry) {
            $callable = $this->resolveListener($entry['listener']);
            $callable($event, ...$payload);
        }
        return $event;
    }

    /**
     * 派发事件并“短路”：按优先级依次调用监听器，返回首个非 null 的返回值并停止后续派发；
     * 若所有监听器都返回 null，则返回 null。常用于“取第一个有结论的监听器”。
     *
     * @param array<int,mixed> $payload
     * @return mixed
     */
    public function until(object $event, array $payload = [])
    {
        $class = get_class($event);
        $keys  = array_merge([$class], class_parents($event), class_implements($event));

        $entries = [];
        foreach ($keys as $key) {
            if (!empty($this->listeners[$key])) {
                array_push($entries, ...$this->listeners[$key]);
            }
        }
        foreach ($this->listeners as $wildKey => $list) {
            if ($wildKey === '*') {
                array_push($entries, ...$list);
            } elseif (str_ends_with($wildKey, '\\*')) {
                $prefix = substr($wildKey, 0, -1);
                if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                    array_push($entries, ...$list);
                }
            }
        }

        usort($entries, static fn ($a, $b) => $b['priority'] <=> $a['priority']);

        foreach ($entries as $entry) {
            $callable = $this->resolveListener($entry['listener']);
            $result   = $callable($event, ...$payload);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    /**
     * 把监听器定义解析为可调用对象（类监听器经容器解析，支撑依赖注入）
     *
     * @param callable|array{0:string,1:string}|string $listener
     */
    protected function resolveListener($listener): callable
    {
        if (is_callable($listener) && !is_string($listener)) {
            return $listener;
        }
        if (is_array($listener) && count($listener) === 2) {
            [$class, $method] = $listener;
            return [$this->resolveClass($class), $method];
        }
        if (is_string($listener) && class_exists($listener)) {
            return [$this->resolveClass($listener), 'handle'];
        }
        throw new \RuntimeException('事件监听器不可调用：' . var_export($listener, true));
    }

    /**
     * 经容器解析类实例（无 App 上下文时直接 new）
     *
     * @return object
     */
    protected function resolveClass(string $class): object
    {
        $app = App::instance();
        if ($app !== null) {
            return $app->container()->make($class);
        }
        return new $class();
    }
}
