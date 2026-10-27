<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Http\Resources;

use Mtphp\Http\Response;
use Mtphp\Pagination\Paginator;

/**
 * API 资源转换器（零依赖，思想借鉴主流框架的 API Resource）
 *
 * 把“原始数据（模型数组 / 分页器）”按接口需要的结构做转换与裁剪，解耦“数据库字段”
 * 与“对外接口字段”。支持单条、集合、分页器三种输入，并可直接转成 JSON 响应。
 *
 * 用法（先定义资源类）：
 *   class UserResource extends JsonResource {
 *       public function toArray($item): array {
 *           return ['id' => $item['id'], 'name' => $item['name']];
 *       }
 *   }
 *
 *   // 集合
 *   return UserResource::collection($users)->response();
 *   // 单条
 *   return (new UserResource($user))->resolve();
 *   // 分页（自动包裹 data / meta）
 *   return UserResource::collection($paginator)->response();
 */
abstract class JsonResource
{
    /** @var mixed 单条数据、数据集合（list）或 Paginator */
    protected $resource;

    public function __construct($resource)
    {
        $this->resource = $resource;
    }

    /**
     * 子类实现：把“单条原始数据”映射为对外接口结构
     *
     * @param mixed $item
     * @return array<string,mixed>
     */
    abstract public function toArray($item): array;

    /**
     * 解析为最终数组（自动识别 单条 / 集合 / 分页器）
     *
     * @return mixed
     */
    public function resolve()
    {
        $r = $this->resource;

        if ($r instanceof Paginator) {
            $arr  = $r->toArray();
            $data = $this->mapMany($arr['data']);
            return [
                'data' => $data,
                'meta' => [
                    'total'        => $arr['total'],
                    'per_page'     => $arr['per_page'],
                    'current_page' => $arr['current_page'],
                    'last_page'    => $arr['last_page'],
                    'has_more'     => $arr['has_more'],
                ],
            ];
        }

        if (is_array($r) && array_is_list($r)) {
            return $this->mapMany($r);
        }

        return $this->toArray($r);
    }

    /**
     * 直接生成 JSON 响应（供控制器 return）
     */
    public function response(int $code = 200): Response
    {
        return (new Response())->json($this->resolve(), $code);
    }

    /**
     * @param list<mixed> $items
     * @return list<array<string,mixed>>
     */
    protected function mapMany(array $items): array
    {
        return array_map(fn ($item) => $this->toArray($item), $items);
    }

    /**
     * 构造单条资源
     *
     * @param mixed $resource
     */
    public static function make($resource): static
    {
        return new static($resource);
    }

    /**
     * 构造集合/分页资源
     *
     * @param mixed $resource
     */
    public static function collection($resource): static
    {
        return new static($resource);
    }
}
