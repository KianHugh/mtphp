<!-- home.tpl 现在是“子模板”，通过 @extends 继承 layouts.app 布局 -->
@extends('layouts.app')

@section('title')
mtphp 首页
@endsection

@section('content')
    <h2>{{ $title }} <span class="badge">{{ $framework }}</span></h2>
    <p>这是一个由 <strong>mtphp</strong> 框架渲染的页面，模板经编译缓存后等价于原生 PHP，零运行时解析开销。</p>

    <h3>框架特性</h3>
    <ul>
    @foreach($features as $f)
        <li>{{ $f }}</li>
    @endforeach
    </ul>

    <p>试试接口：<code>/hello/世界</code> · <code>/api/ping</code> · <code>/db</code> · <code>/demo/layout</code></p>
    <p>📖 完整文档：<a href="/docs">/docs（左菜单 + 右内容，当前页即序言）</a></p>
@endsection
