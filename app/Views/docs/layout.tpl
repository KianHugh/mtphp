<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- 文档布局：左侧菜单 + 右侧内容；标题由子模板 @section('title') 提供 -->
    <title>@yield('title', 'mtphp 框架文档')</title>
    <style>
        :root { --teal:#0f766e; --teal-d:#0b5d56; --border:#e5e7eb; --text:#1f2937; --muted:#6b7280; --bg:#f8fafc; }
        * { box-sizing: border-box; }
        body { margin:0; font-family:-apple-system,"PingFang SC","Microsoft YaHei",Arial,sans-serif; color:var(--text); background:var(--bg); }
        .topbar { display:flex; align-items:center; justify-content:space-between; height:56px; padding:0 24px; background:var(--teal); color:#fff; position:sticky; top:0; z-index:10; }
        .topbar .brand { font-size:18px; font-weight:700; color:#fff; text-decoration:none; }
        .topbar nav a { color:#d1fae5; text-decoration:none; margin-left:18px; font-size:14px; }
        .topbar nav a:hover { color:#fff; }
        .doc { display:flex; max-width:1200px; margin:0 auto; align-items:flex-start; }
        .sidebar { width:240px; flex:0 0 240px; position:sticky; top:56px; align-self:flex-start; height:calc(100vh - 56px); overflow:auto; padding:20px 12px; border-right:1px solid var(--border); background:#fff; }
        .sidebar .group-title { font-size:12px; color:var(--muted); margin:14px 8px 6px; letter-spacing:.5px; }
        .sidebar a { display:block; padding:7px 10px; border-radius:6px; color:var(--text); text-decoration:none; font-size:14px; line-height:1.4; }
        .sidebar a:hover { background:#ecfdf5; }
        .sidebar a.active { background:var(--teal); color:#fff; font-weight:600; }
        .content { flex:1 1 auto; min-width:0; padding:28px 36px 60px; background:#fff; }
        .content h1 { font-size:26px; margin:0 0 16px; color:var(--teal-d); }
        .content h2 { font-size:20px; margin:28px 0 12px; padding-bottom:6px; border-bottom:1px solid var(--border); }
        .content h3 { font-size:16px; margin:20px 0 10px; }
        .content p { line-height:1.8; }
        .content code { background:#f1f5f9; padding:2px 6px; border-radius:4px; font-family:Consolas,Menlo,monospace; font-size:13px; }
        .content pre { background:#0f172a; color:#e2e8f0; padding:14px 16px; border-radius:8px; overflow:auto; }
        .content pre code { background:transparent; color:inherit; padding:0; }
        .content table { border-collapse:collapse; width:100%; margin:14px 0; font-size:14px; }
        .content th, .content td { border:1px solid var(--border); padding:8px 10px; text-align:left; }
        .content th { background:#f1f5f9; }
        .content blockquote { margin:14px 0; padding:10px 16px; background:#f0fdfa; border-left:4px solid var(--teal); color:#134e4a; border-radius:0 6px 6px 0; }
        .content ul, .content ol { line-height:1.9; }
        .content a { color:var(--teal); }
        .pager { display:flex; justify-content:space-between; margin-top:40px; padding-top:20px; border-top:1px solid var(--border); }
        .pager a { text-decoration:none; color:var(--teal); font-size:14px; }
        .pager .next { text-align:right; }
        .pager .muted { color:var(--muted); }
        .doc-footer { text-align:center; color:var(--muted); font-size:13px; padding:20px; border-top:1px solid var(--border); background:#fff; }
    </style>
</head>
<body>
    <header class="topbar">
        <a class="brand" href="/docs">mtphp 文档</a>
        <nav>
            <a href="/">首页</a>
            <a href="/demo/ui">指令演示</a>
            <a href="/demo/layout">布局演示</a>
        </nav>
    </header>

    <div class="doc">
        <aside class="sidebar">
            <div class="group-title">文档目录</div>
            <a href="/docs" class="@class(['active' => ($slug ?? '') === ''])">序言</a>
            @foreach($menu as $item)
            <a href="/docs/{{ $item['slug'] }}" class="@class(['active' => ($slug ?? '') === $item['slug']])">{{ $item['title'] }}</a>
            @endforeach
        </aside>

        <main class="content">
            @yield('content')
        </main>
    </div>

    <footer class="doc-footer">
        © 武汉麦麦心选网络科技有限公司 · mtphp 框架文档
    </footer>
</body>
</html>
