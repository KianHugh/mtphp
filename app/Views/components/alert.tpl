{{-- 组件视图：通过 @props 声明带默认值的属性；调用方用 @component 传值 --}}
@props(['type' => 'info', 'title' => ''])

<div class="alert alert-{{ $type }}" style="border:1px solid #0f766e;border-radius:8px;padding:12px 16px;margin:12px 0;">
    @if ($title)
        <strong>{{ $title }}</strong>
    @endif
    {{-- $slot 是调用方写在 @component...@endcomponent 之间的“默认插槽”内容 --}}
    <div>{!! $slot !!}</div>
</div>
