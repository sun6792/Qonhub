<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $workspace->name ?? '客户看板' }} - Qonhub AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    @stack('head')
</head>
<body class="bg-gray-50 min-h-screen">
    <nav class="bg-white shadow-sm border-b">
        <div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                @if ($workspace->logo_url)
                <img src="{{ $workspace->logo_url }}" class="h-8 w-8 rounded" alt="logo">
                @endif
                <div>
                    <span class="font-bold text-lg text-gray-800">{{ $workspace->name }}</span>
                    <span class="text-sm text-gray-400 ml-2">内容运营看板</span>
                </div>
            </div>
            <div class="text-sm text-gray-500">
                Powered by <span class="font-semibold text-blue-600">Qonhub AI</span>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-6">
        <div class="flex space-x-2 mb-6">
            <a href="{{ route('client.dashboard') }}"
               class="px-4 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('client.dashboard') ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' }}">
                📊 总览
            </a>
            <a href="{{ route('client.articles') }}"
               class="px-4 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('client.articles') ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' }}">
                📝 文章列表
            </a>
            <a href="{{ route('client.ai-visibility') }}"
               class="px-4 py-2 rounded-lg text-sm font-medium {{ request()->routeIs('client.ai-visibility') ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' }}">
                🤖 AI可见度
            </a>
            <form method="POST" action="{{ route('client.logout') }}" class="inline">
                @csrf
                <button class="px-4 py-2 rounded-lg text-sm font-medium bg-white text-gray-500 hover:bg-red-50 hover:text-red-600">
                    退出
                </button>
            </form>
        </div>

        @yield('content')
    </div>

    <footer class="text-center text-gray-400 text-xs py-6">
        &copy; {{ date('Y') }} Qonhub AI · 数据实时更新
    </footer>
</body>
</html>
