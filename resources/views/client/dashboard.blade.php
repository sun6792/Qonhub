@extends('client.layout')

@section('content')
<!-- 统计卡片 -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm p-5">
        <div class="text-2xl font-bold text-blue-600">{{ $publishedCount }}</div>
        <div class="text-sm text-gray-500 mt-1">累计发布文章</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5">
        <div class="text-2xl font-bold text-green-600">{{ $thisMonthCount }}</div>
        <div class="text-sm text-gray-500 mt-1">本月新增</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5">
        <div class="text-2xl font-bold text-purple-600">{{ $connectionStats['connected'] }}/{{ $connectionStats['total'] }}</div>
        <div class="text-sm text-gray-500 mt-1">平台已授权</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm p-5">
        <div class="text-2xl font-bold text-orange-600">{{ $visibilityData['last_checked_at'] }}</div>
        <div class="text-sm text-gray-500 mt-1">AI检测更新</div>
    </div>
</div>

<!-- AI可见度总览 -->
<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
    <h3 class="font-bold text-lg mb-4">🤖 AI 引用情况</h3>
    @php $scores = $visibilityData['latest_scores'] ?? []; @endphp
    @php $aiPlatforms = \App\Services\GeoFlow\AiVisibilityService::AI_PLATFORMS; @endphp
    @if (!empty($scores))
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
        @foreach ($aiPlatforms as $key => $info)
        @php $data = $scores[$key] ?? ['score' => 0, 'trend' => 'new', 'mentioned' => 0]; @endphp
        <div class="border rounded-lg p-3">
            <div class="flex justify-between items-center mb-2">
                <span class="font-medium text-sm text-gray-700">{{ $info['icon'] }} {{ $info['name'] }}</span>
                <span class="text-xs px-2 py-0.5 rounded-full
                    {{ $data['trend'] === 'up' ? 'bg-green-100 text-green-700' : ($data['trend'] === 'down' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-500') }}">
                    {{ $data['trend'] === 'up' ? '↗' : ($data['trend'] === 'down' ? '↘' : ($data['trend'] === 'new' ? '🆕' : '→')) }}
                    {{ $data['score'] }}%
                </span>
            </div>
            <div class="bg-gray-200 rounded-full h-2 mb-1">
                <div class="h-2 rounded-full {{ $data['score'] > 50 ? 'bg-green-500' : ($data['score'] > 20 ? 'bg-yellow-500' : 'bg-gray-400') }}"
                     style="width: {{ max($data['score'], 5) }}%"></div>
            </div>
            <div class="text-xs text-gray-400">提及: {{ $data['mentioned'] }} 次</div>
        </div>
        @endforeach
    </div>
    @else
    <div class="text-center py-6 text-gray-400">
        <p>AI引用检测数据收集中...</p>
        <p class="text-sm">系统将在每日凌晨自动检测品牌在各大AI中的引用情况</p>
    </div>
    @endif
</div>

<!-- 最新文章 -->
<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
    <div class="flex justify-between items-center mb-4">
        <h3 class="font-bold text-lg">📝 最新发布文章</h3>
        <a href="{{ route('client.articles') }}" class="text-blue-600 text-sm hover:underline">查看全部 →</a>
    </div>
    @if ($articles->isNotEmpty())
    <div class="space-y-3">
        @foreach ($articles as $article)
        <div class="border-b pb-3 last:border-0">
            <h4 class="font-medium text-gray-800">{{ $article->title }}</h4>
            <div class="flex gap-4 text-xs text-gray-400 mt-1">
                <span>📅 {{ $article->published_at?->format('Y-m-d') ?? '-' }}</span>
                <span>🏷️ {{ $article->keywords ?? '-' }}</span>
                <span>📊 {{ $article->status }}</span>
            </div>
        </div>
        @endforeach
    </div>
    @else
    <div class="text-center py-6 text-gray-400">暂无已发布文章</div>
    @endif
</div>

<!-- 我的分发平台 -->
<div class="bg-white rounded-xl shadow-sm p-5">
    <div class="flex justify-between items-center mb-4">
        <h3 class="font-bold text-lg">📡 我的分发平台</h3>
        <span class="text-xs text-gray-400">{{ $connectionStats['connected'] }}/{{ $connectionStats['total'] }} 已授权</span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        @foreach ($platforms as $p)
        @php $platform = is_array($p) ? $p : (is_object($p) ? (array)$p : []); $connected = $platform['connected'] ?? false; @endphp
        <div class="border rounded-lg p-4 flex items-center justify-between {{ $connected ? 'border-green-200 bg-green-50/30' : 'border-gray-200' }}">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-sm font-bold"
                     style="background-color: {{ $platform['color'] ?? '#6b7280' }}">
                    {{ mb_substr($platform['name'] ?? '?', 0, 1) }}
                </div>
                <div>
                    <div class="font-medium text-sm">{{ $platform['name'] ?? '' }}</div>
                    <div class="text-xs {{ $connected ? 'text-green-600' : 'text-gray-400' }}">
                        @if ($connected) ✅ 已授权 @else ⚠️ 未授权 @endif
                    </div>
                </div>
            </div>
            <div>
            @if ($connected)
                <span class="text-xs text-green-600 font-medium">已连接</span>
            @else
                <button onclick="showAuthGuide('{{ $platform['name'] ?? '' }}', '{{ $platform['login_url'] ?? '#' }}')"
                        class="text-xs bg-blue-600 text-white px-3 py-1.5 rounded-lg hover:bg-blue-700 transition font-medium">
                    授权连接
                </button>
            @endif
            </div>
        </div>
        @endforeach
    </div>
    <div class="mt-4 p-3 bg-blue-50 rounded-lg text-sm text-blue-700">
        💡 <strong>提示：</strong>授权平台后，运营团队可直接将文章发布到你的账号，无需每次询问密码。
    </div>
</div>

{{-- 授权指引弹窗 --}}
<div id="auth-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4 p-6" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <h4 class="text-lg font-bold" id="auth-modal-title">连接平台</h4>
            <button onclick="document.getElementById('auth-modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <div class="space-y-4 text-sm text-gray-600">
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-amber-800 text-xs">
                ⚠️ 如尚未注册该平台账号，请先前往平台官网完成注册。
            </div>
            <div class="space-y-2">
                <div class="font-medium text-gray-800">📋 操作步骤：</div>
                <ol class="list-decimal ml-4 space-y-2">
                    <li>点击下方按钮打开 <span id="auth-platform-name" class="font-medium"></span></li>
                    <li>使用你的账号密码正常登录平台</li>
                    <li>登录后通知运营人员"<span id="auth-platform-name2" class="font-medium"></span> 已授权"</li>
                </ol>
            </div>
            <a id="auth-platform-link" href="#" target="_blank" rel="noopener noreferrer"
               class="block w-full text-center rounded-lg bg-blue-600 text-white py-3 font-medium hover:bg-blue-700 transition">
                前往平台登录 →
            </a>
        </div>
    </div>
</div>
<script>
function showAuthGuide(name, url) {
    document.getElementById('auth-modal-title').textContent = '连接 ' + name;
    document.getElementById('auth-platform-name').textContent = name;
    document.getElementById('auth-platform-name2').textContent = name;
    document.getElementById('auth-platform-link').href = url;
    document.getElementById('auth-modal').classList.remove('hidden');
}
</script>
@endsection
