<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>客户登录 - Qonhub AI</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold text-gray-800">Qonhub AI</h1>
            <p class="text-gray-500 mt-2">GEO内容运营看板</p>
        </div>

        <div class="bg-white rounded-xl shadow-sm p-8">
            @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 mb-4 text-sm">
                {{ $errors->first() }}
            </div>
            @endif

            <form method="POST" action="{{ route('client.login.attempt') }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">账号</label>
                    <input type="text" name="username" value="{{ old('username') }}" required
                           class="w-full rounded-lg border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                           placeholder="输入账号">
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">密码</label>
                    <input type="password" name="password" required
                           class="w-full rounded-lg border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500"
                           placeholder="输入密码">
                </div>
                <button type="submit"
                        class="w-full bg-blue-600 text-white rounded-lg py-2.5 font-medium text-sm hover:bg-blue-700 transition">
                    登录
                </button>
            </form>
        </div>

        <p class="text-center text-xs text-gray-400 mt-6">
            首次使用？请联系您的运营团队获取账号
        </p>
    </div>
</body>
</html>
