<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- 布局用 @yield 输出标题；子模板未定义时用默认“mtphp 应用” -->
    <title>@yield('title', 'mtphp 应用')</title>
    <style>
        body { font-family: -apple-system, "PingFang SC", Arial, sans-serif; max-width: 860px; margin: 0 auto; padding: 0 16px; color: #1f2937; }
        header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #0f766e; padding: 14px 0; }
        header h1 { color: #0f766e; font-size: 20px; margin: 0; }
        main { min-height: 50vh; padding: 20px 0; }
        aside { background: #f3f4f6; border-radius: 8px; padding: 12px 16px; margin: 16px 0; }
        footer { border-top: 1px solid #e5e7eb; padding: 14px 0; color: #6b7280; font-size: 13px; }
        .badge { display: inline-block; background: #0f766e; color: #fff; padding: 2px 10px; border-radius: 12px; font-size: 13px; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
        ul { line-height: 2; }
    </style>
</head>
<body>
    <header>
        <h1>mtphp 站点</h1>
        <span class="badge">布局模板演示</span>
    </header>

    <!-- 侧边栏：默认内容写在 @yield 第二参数；子模板可用 @parent 在此基础追加 -->
    <aside>
        @yield('sidebar', '默认侧边栏：可在子模板基础上追加内容')
    </aside>

    <!-- 主体内容：由子模板的 @section('content') 提供 -->
    <main>
        @yield('content')
    </main>

    <footer>
        <p>© 武汉麦麦心选网络科技有限公司 · @yield('year', '2026')</p>
        @yield('scripts')
    </footer>
</body>
</html>
