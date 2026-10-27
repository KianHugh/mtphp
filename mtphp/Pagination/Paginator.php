<?php
declare(strict_types=1);

// 版权所有 (c) 武汉麦麦心选网络科技有限公司

namespace Mtphp\Pagination;

/**
 * 分页器（零依赖，思想借鉴主流框架的 Paginator）
 *
 * 由 Query::paginate() / Model::paginate() 产出，封装“当前页数据 + 总数 + 页码”等，
 * 既可直接取数据做 API 返回，也可渲染简单的 HTML 翻页条。返回的 items 为数组，
 * 因此与 Model 的数组结果天然契合。
 */
class Paginator
{
    /** @var array<int,array<string,mixed>> 当前页数据 */
    protected array $items;

    protected int $total;

    protected int $perPage;

    protected int $currentPage;

    protected string $path = '';

    /** 简单分页（无 COUNT）：true 时 total/lastPage 无意义，hasMorePages 由 $hasMore 决定 */
    protected bool $simple = false;

    /** 简单分页下“是否存在下一页”（由调用方按“多取 1 行”判定） */
    protected bool $hasMore = false;

    /**
     * @param array<int,array<string,mixed>> $items
     * @param bool $simple 简单分页（不统计总数）
     * @param bool $hasMore 简单分页下是否存在下一页
     */
    public function __construct(array $items, int $total, int $perPage, int $currentPage, string $path = '', bool $simple = false, bool $hasMore = false)
    {
        $this->items       = $items;
        $this->total       = $total;
        $this->perPage     = $perPage;
        $this->currentPage = max(1, $currentPage);
        $this->path        = $path;
        $this->simple      = $simple;
        $this->hasMore     = $hasMore;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->simple ? -1 : $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): int
    {
        if ($this->simple) {
            return -1;
        }
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasMorePages(): bool
    {
        return $this->simple ? $this->hasMore : ($this->currentPage < $this->lastPage());
    }

    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    public function previousPageUrl(): ?string
    {
        return $this->onFirstPage() ? null : $this->url($this->currentPage - 1);
    }

    public function nextPageUrl(): ?string
    {
        return $this->hasMorePages() ? $this->url($this->currentPage + 1) : null;
    }

    /**
     * 设置翻页条的基础路径（通常为当前请求路径）
     */
    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    protected function url(int $page): string
    {
        $base = $this->path ?: '/';
        $sep  = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . 'page=' . $page;
    }

    /**
     * 简单 HTML 翻页条（上一页 / [页码] / 下一页）
     *
     * 简单分页（无总数）下仅渲染上一页 / 下一页，不显示页码。
     */
    public function links(): string
    {
        $html  = '<nav class="pagination">';
        $html .= $this->previousPageUrl() !== null
            ? '<a href="' . htmlspecialchars($this->previousPageUrl(), ENT_QUOTES) . '">上一页</a>'
            : '<span class="disabled">上一页</span>';
        if ($this->simple) {
            $html .= $this->nextPageUrl() !== null
                ? ' <a href="' . htmlspecialchars($this->nextPageUrl(), ENT_QUOTES) . '">下一页</a>'
                : ' <span class="disabled">下一页</span>';
        } else {
            $html .= ' <span>第 ' . $this->currentPage . ' / ' . $this->lastPage() . ' 页</span> ';
            $html .= $this->nextPageUrl() !== null
                ? '<a href="' . htmlspecialchars($this->nextPageUrl(), ENT_QUOTES) . '">下一页</a>'
                : '<span class="disabled">下一页</span>';
        }
        $html .= '</nav>';
        return $html;
    }

    /**
     * 转数组（便于 API 返回；data 为本页数据，meta 为分页元信息）
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'data'         => $this->items,
            'total'        => $this->total(),
            'per_page'     => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page'    => $this->lastPage(),
            'has_more'     => $this->hasMorePages(),
        ];
    }
}
