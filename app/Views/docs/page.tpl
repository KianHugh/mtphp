@extends('docs.layout')

@section('title')
{{ $title }} - mtphp 文档
@endsection

@section('content')
    <h1>{{ $title }}</h1>

    {!! $content !!}

    <div class="pager">
        @if($prev)
        <a href="/docs/{{ $prev['slug'] }}">← {{ $prev['title'] }}</a>
        @else
        <span class="muted">← 序言</span>
        @endif

        @if($next)
        <a class="next" href="/docs/{{ $next['slug'] }}">{{ $next['title'] }} →</a>
        @else
        <span class="muted next">已是最后一节</span>
        @endif
    </div>
@endsection
