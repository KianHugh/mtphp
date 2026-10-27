{{-- 这是一条 Blade 风格注释，编译后完全移除，不会出现在 HTML 中 --}}
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>mtphp 模板指令演示</title>
    <style>
        body { font-family: -apple-system,"PingFang SC",Arial,sans-serif; max-width: 900px; margin: 0 auto; padding: 0 16px; color:#1f2937; }
        h1 { color:#0f766e; } h2 { border-bottom:1px solid #e5e7eb; padding-bottom:6px; margin-top:28px; }
        .odd { color:#b45309; } .even { color:#1d4ed8; }
        .alert { border-radius:8px; padding:12px 16px; margin:12px 0; }
        .alert-success { background:#ecfdf5; border:1px solid #10b981; }
        .ok { color:#059669; } .warn { color:#d97706; } .err { color:#dc2626; }
        pre { background:#f3f4f6; padding:10px; border-radius:6px; overflow:auto; }
        code { background:#f3f4f6; padding:2px 6px; border-radius:4px; }
    </style>
</head>
<body>
    <h1>mtphp 模板指令演示</h1>
    <p>该页面集中演示 mtphp 模板引擎的常用指令（Blade 风格，思想借鉴、独立实现的主流写法，调用依旧极简）。</p>

    <h2>1. 循环变量 <code>$loop</code>（@@foreach）</h2>
    <ul>
    @foreach ($products as $p)
        <li class="@class(['odd' => $loop->odd, 'even' => $loop->even])">
            #{{ $loop->iteration }}/{{ $loop->count }}
            @if ($loop->first) <span class="badge">首发</span> @endif
            {{ $p['name'] }} - {{ $p['price'] }} 元
            @if ($loop->last) <em>（本项遍历后还剩 {{ $loop->remaining }} 项）</em> @endif
        </li>
    @endforeach
    </ul>

    <h2>2. @@forelse（集合为空时走 @@empty 分支）</h2>
    <ul>
    @forelse ($emptyList as $e)
        <li>{{ $e }}</li>
    @empty
        <li>暂无数据（走了 @empty 分支）</li>
    @endforelse
    </ul>

    <h2>3. @@switch / @@case / @@default</h2>
    @switch ($status)
        @case('ok')
            <p class="ok">状态正常</p>
            @break
        @case('warn')
            <p class="warn">需要注意</p>
            @break
        @default
            <p class="err">未知状态：{{ $status }}</p>
    @endswitch

    <h2>4. 组件 @@component（含 @@slot 与 @@props）</h2>
    @component('components.alert', ['type' => 'success', 'title' => '提交成功'])
        订单已提交，我们会尽快为你处理。
    @endcomponent

    <h2>5. 局部 @@include（页脚）</h2>
    @include('partials.footer', ['year' => 2026])

    <h2>6. 运行期数据 @@json</h2>
    <pre>@json($products)</pre>

    <h2>7. 资源栈 @@push / @@stack（合并注入脚本）</h2>
    @push('scripts')
        <script>console.log('mtphp ui demo script 1');</script>
    @endpush
    @stack('scripts')

    <h2>8. @@verbatim（原样保留，不编译）</h2>
    @verbatim
        <span>{{ vueVar }} 与 @vueDirective 都不会被模板引擎处理，可直接写前端框架模板</span>
    @endverbatim

    <h2>9. @@php 原样 PHP 块</h2>
    @php
        $computed = 6 * 7;
    @endphp
    <p>@php echo $computed; @endphp 是由 @php 块计算得出的结果。</p>

    <h2>10. 转义 vs 原样输出</h2>
    <p>转义输出：{{ '<b>bold</b>' }}</p>
    <p>原样输出：{!! '<b>bold</b>' !!}</p>
</body>
</html>
