<!-- page.tpl：演示布局继承 + @parent（在布局默认内容基础上追加） -->
@extends('layouts.app')

@section('title')
布局模板演示页
@endsection

@section('content')
    <div class="card">
        <h3>本页通过 @extends 继承 layouts.app 布局</h3>
        <p>页面内容用“区块（section）”定义，布局用“占位（yield）”输出；本页通过 @extends 声明继承 layouts.app 布局。</p>
        <p>当前时间：{{ $now }}</p>
    </div>
@endsection

{{-- @parent：先输出布局 sidebar 的默认内容，再追加本模板的菜单 --}}
@section('sidebar')
@parent
<ul>
    <li>子模板追加菜单项 A</li>
    <li>子模板追加菜单项 B</li>
</ul>
@endsection

@section('year')
2026
@endsection

@section('scripts')
<script>console.log('mtphp layout demo page loaded');</script>
@endsection
