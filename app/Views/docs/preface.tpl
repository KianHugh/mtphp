@extends('docs.layout')

@section('title')
mtphp 框架文档 · 序言
@endsection

@section('content')
    <h1>mtphp 框架</h1>
    <p>一款面向现代 PHP（<strong>PHP 8.0+</strong>）的高性能、高并发 Web 框架。设计目标：目录清晰、模块分工明确、路由 / 视图 / 数据库开箱即用，并原生支持 Swoole 常驻内存 + 协程连接池的高并发运行模式。</p>
    <p>本框架追求“使用简单、配置简单”——核心组件均零配置即可用，并配有详尽中文注释。模板经编译缓存后等价于原生 PHP，无运行时解析开销。</p>

    <h2>核心特性</h2>
    <ul>
    @foreach($features as $f)
        <li>{{ $f }}</li>
    @endforeach
    </ul>

    <p>从 <a href="/docs/1">环境要求 →</a> 开始阅读，或直接查看 <a href="/demo/ui">模板指令演示</a> 与 <a href="/demo/layout">布局演示</a>。</p>
@endsection
