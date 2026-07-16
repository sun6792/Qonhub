@extends('client.layout')

@section('content')
@php $scores = $visibilityData['latest_scores'] ?? []; @endphp
@php $aiPlatforms = \App\Services\GeoFlow\AiVisibilityService::AI_PLATFORMS; @endphp

<div class="space-y-5">
    {{-- P1 内容需求提交 --}}
    <div id="contentRequestCard" class="bento-card p-5" style="background:rgba(22,24,40,0.9); border-color:rgba(165,180,252,0.2)">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-ai-primary">📝 提交内容需求</h3>
            <button onclick="document.getElementById('requestForm').classList.toggle('hidden')" class="text-xs px-3 py-1 rounded-lg" style="background:rgba(99,102,241,0.15); color:#a5b4fc">+ 新需求</button>
        </div>
        <form id="requestForm" class="hidden space-y-3" method="POST" action="{{ route('client.content-request.store') }}">
            @csrf
            <input type="text" name="topic" placeholder="希望生成什么主题的内容？" required class="w-full rounded-xl px-3 py-2 text-sm text-white placeholder-gray-500 focus:outline-none" style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.15)">
            <textarea name="notes" placeholder="补充说明（目标人群、关键词、特殊要求等）" rows="2" class="w-full rounded-xl px-3 py-2 text-sm text-white placeholder-gray-500 focus:outline-none" style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.15)"></textarea>
            <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-white" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">提交需求</button>
        </form>
        <p class="text-xs text-ai-dim mt-2">提交后运营团队将根据需求创建AI内容生成任务</p>
    </div>

    {{-- 🏢 数字身份卡 — v2.7.0 整合企业档案+B2B锚点+平台授权 --}}
    @php
        $profile = $workspace->enterpriseProfile;
        $napOk = $profile && $profile->company_full_name && ($profile->company_phone || $profile->company_website);
        $boundCount = ($connectionStats['connected'] ?? 0);
        $hasPublish = ($publishStats['recent_total'] ?? 0) > 0;
    @endphp
    <div class="bento-card p-5" style="background:rgba(22,24,40,0.9); border-color:rgba(129,140,248,0.25)">
        <h2 class="text-base font-semibold mb-3" style="color:#ddd6fe">🏢 企业数字身份</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div class="space-y-1">
                <div class="text-xs text-ai-dim">企业档案</div>
                <span class="font-medium" style="color:{{ $napOk ? '#4ade80' : '#fbbf24' }}">
                    {{ $napOk ? '✅ 已完善' : '⚠️ 待完善' }}
                </span>
                @if($profile && $profile->company_full_name)
                <div class="text-xs text-ai-dim truncate" title="{{ $profile->company_full_name }}">{{ $profile->company_full_name }}</div>
                @endif
            </div>
            <div class="space-y-1">
                <div class="text-xs text-ai-dim">平台授权</div>
                <span class="font-medium" style="color:{{ $boundCount > 0 ? '#4ade80' : '#9ca3af' }}">
                    {{ $boundCount }} 个已绑定
                </span>
            </div>
            <div class="space-y-1">
                <div class="text-xs text-ai-dim">B2B 锚点</div>
                @if($anchorData)
                <span class="font-medium" style="color:{{ $anchorData['certified_count'] > 0 ? '#4ade80' : '#fbbf24' }}">
                    {{ $anchorData['certified_count'] }}/{{ $anchorData['total_count'] }} 已认证
                </span>
                @else
                <span class="font-medium" style="color:#9ca3af">未建档</span>
                @endif
            </div>
            <div class="space-y-1">
                <div class="text-xs text-ai-dim">近期分发</div>
                <span class="font-medium" style="color:{{ $hasPublish ? '#4ade80' : '#9ca3af' }}">
                    {{ $publishStats['recent_success'] ?? 0 }}/{{ $publishStats['recent_total'] ?? 0 }} 成功
                </span>
            </div>
        </div>
    </div>

    {{-- 📡 平台资产总览 — B2B认证 + 发布授权 + 最近任务 --}}
    <div class="bento-card p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold" style="color:#ddd6fe">📡 平台资产</h2>
            <div class="flex gap-2">
                <a href="{{ route('client.content-publish.certify') }}" class="text-xs px-3 py-1.5 rounded-lg" style="background:rgba(99,102,241,0.15);color:#a5b4fc">🏢 B2B入驻</a>
                <a href="{{ route('client.content-publish.create') }}" class="text-xs px-3 py-1.5 rounded-lg" style="background:rgba(34,197,94,0.15);color:#4ade80">🚀 一键发布</a>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div class="space-y-1">
                <div class="text-xs text-ai-dim">B2B 认证</div>
                <span class="font-medium" style="color:{{ ($platformAssets['b2b_certified']??0) > 0 ? '#4ade80' : '#fbbf24' }}">
                    {{ $platformAssets['b2b_certified'] ?? 0 }}/{{ $platformAssets['b2b_total'] ?? 0 }}
                </span>
                <div class="text-xs text-ai-dim">已认证平台</div>
            </div>
            <div class="space-y-1">
                <div class="text-xs text-ai-dim">发布授权</div>
                <span class="font-medium" style="color:{{ ($platformAssets['publish_authorized']??0) > 0 ? '#4ade80' : '#9ca3af' }}">
                    {{ $platformAssets['publish_authorized'] ?? 0 }}/{{ $platformAssets['publish_total'] ?? 0 }}
                </span>
                <div class="text-xs text-ai-dim">已授权平台</div>
            </div>
            <div class="space-y-1">
                <div class="text-xs text-ai-dim">发布任务</div>
                <span class="font-medium" style="color:#a5b4fc">{{ $publishStats['total_tasks'] ?? 0 }}</span>
                <div class="text-xs text-ai-dim">累计任务</div>
            </div>
            <div class="space-y-1">
                <div class="text-xs text-ai-dim">7日成功率</div>
                @php $rate = ($publishStats['recent_total'] ?? 0) > 0 ? round(($publishStats['recent_success']??0)/($publishStats['recent_total']??1)*100) : 0; @endphp
                <span class="font-medium" style="color:{{ $rate >= 80 ? '#4ade80' : ($rate >= 50 ? '#fbbf24' : '#f87171') }}">{{ $rate }}%</span>
                <div class="text-xs text-ai-dim">{{ $publishStats['recent_success'] ?? 0 }}/{{ $publishStats['recent_total'] ?? 0 }} 成功</div>
            </div>
        </div>
        {{-- 最近任务 --}}
        @if(!empty($platformAssets['recent_publish_tasks']))
        <div class="mt-4 border-t pt-3" style="border-color:rgba(255,255,255,0.06)">
            <div class="text-xs text-ai-dim mb-2">最近发布任务</div>
            <div class="space-y-1">
                @foreach($platformAssets['recent_publish_tasks'] as $pt)
                <a href="{{ route('client.content-publish.show', $pt['id']) }}" class="flex items-center justify-between text-xs hover:bg-indigo-50/5 rounded px-2 py-1">
                    <span class="text-ai-primary truncate mr-2">{{ $pt['name'] }}</span>
                    <span class="text-ai-dim mr-2">{{ $pt['created_at'] }}</span>
                    <span class="px-1.5 py-0.5 rounded-full text-[10px] {{ $pt['status'] === 'completed' ? 'bg-emerald-500/10 text-emerald-400' : ($pt['status'] === 'failed' ? 'bg-red-500/10 text-red-400' : 'bg-blue-500/10 text-blue-400') }}">
                        {{ $pt['status'] === 'completed' ? '✅' : ($pt['status'] === 'failed' ? '❌' : '⏳') }} {{ $pt['progress'] }}%
                    </span>
                </a>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    {{-- KPI Bento Grid — 统一 indigo 色系 --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <div class="bento-card kpi-card p-5 text-center">
            <div class="text-3xl font-bold gradient-ai">{{ $publishedCount }}</div>
            <div class="text-xs text-ai-secondary mt-1">累计发布文章</div>
        </div>
        <div class="bento-card kpi-card p-5 text-center" style="animation-delay:1s">
            <div class="text-3xl font-bold" style="color:#a5b4fc">{{ $thisMonthCount }}</div>
            <div class="text-xs text-ai-secondary mt-1">本月新增</div>
        </div>
        <div class="bento-card kpi-card p-5 text-center" style="animation-delay:2s">
            <div class="text-3xl font-bold" style="color:#c4b5fd">{{ $connectionStats['connected'] }}/{{ $connectionStats['total'] }}</div>
            <div class="text-xs text-ai-secondary mt-1">平台已授权</div>
        </div>
        <div class="bento-card kpi-card p-5 text-center" style="animation-delay:3s">
            <div class="text-base font-bold truncate px-1" style="color:#a5b4fc">{{ $visibilityData['last_checked_at'] }}</div>
            <div class="text-xs text-ai-secondary mt-1">AI检测更新</div>
        </div>
        @if (($publishStats['total_tasks'] ?? 0) > 0)
        <a href="{{ route('client.content-publish.index') }}" class="bento-card kpi-card p-5 text-center block" style="animation-delay:4s">
            <div class="text-3xl font-bold" style="color:#c4b5fd">{{ $publishStats['total_tasks'] }}</div>
            <div class="text-xs text-ai-secondary mt-1">发布任务</div>
        </a>
        @endif
    </div>

    {{-- AI平台覆盖 + B2B锚点 — 2列 Bento --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        {{-- AI 引用情况 --}}
        <div class="bento-card p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-semibold text-ai-primary">🤖 AI 平台引用</h3>
                <a href="{{ route('client.ai-visibility') }}" class="text-xs text-indigo-400 hover:text-indigo-300">详情 →</a>
            </div>
            @if (!empty($scores))
            <div class="grid grid-cols-2 gap-2">
                @foreach (array_slice($aiPlatforms, 0, 6) as $key => $info)
                @php $d = $scores[$key] ?? ['score'=>0,'trend'=>'new','mentioned'=>0]; @endphp
                <div class="rounded-xl border p-3 transition hover:border-indigo-400/20"
                     style="background:rgba(14,16,28,0.5); border-color:rgba(99,102,241,0.1)">
                    <div class="flex justify-between items-center mb-1.5">
                        <span class="text-xs text-ai-secondary">{{ $info['icon'] }} {{ $info['name'] }}</span>
                        <span class="text-[10px] px-1.5 py-0.5 rounded-full font-medium
                            {{ ($d['score']??0) > 50 ? 'bg-emerald-500/20 text-emerald-400' : (($d['score']??0) > 20 ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 text-ai-dim') }}">
                            {{ $d['score'] }}%
                        </span>
                    </div>
                    <div class="bg-white/5 rounded-full h-1.5">
                        <div class="h-1.5 rounded-full transition-all" style="width:{{ max($d['score']??0, 3) }}%; background:{{ $info['color'] }}"></div>
                    </div>
                    <div class="text-[10px] text-ai-dim mt-1">提及 {{ $d['mentioned'] }} 次</div>
                </div>
                @endforeach
            </div>
            @else
            <div class="text-center py-6 text-ai-dim text-sm">AI引用数据收集中...</div>
            @endif
        </div>

        {{-- B2B 锚点 + 文章 --}}
        <div class="space-y-5">
            @if ($anchorData && $anchorData['certified_count'] > 0)
            <div class="bento-card p-5">
                <h3 class="text-base font-semibold text-ai-primary mb-3">🏢 品牌信息锚点</h3>
                <div class="flex justify-between text-sm mb-1.5">
                    <span class="text-ai-secondary">B2B平台认证</span>
                    <span class="font-bold {{ $anchorData['certified_count'] === $anchorData['total_count'] ? 'text-emerald-400' : 'text-blue-400' }}">
                        {{ $anchorData['certified_count'] }}/{{ $anchorData['total_count'] }}
                    </span>
                </div>
                <div class="bg-white/5 rounded-full h-2 mb-3">
                    <div class="h-2 rounded-full {{ $anchorData['certified_count'] >= $anchorData['total_count'] / 2 ? 'bg-indigo-500' : 'bg-amber-500' }}"
                         style="width:{{ round($anchorData['certified_count'] / $anchorData['total_count'] * 100) }}%"></div>
                </div>
                <div class="flex flex-wrap gap-1.5 mb-3">
                    @foreach ($anchorData['certified_platforms'] as $name)
                    <span class="text-[10px] px-2 py-1 rounded-md"
                          style="background:rgba(167,139,250,0.15); color:#c4b5fd">✅ {{ $name }}</span>
                    @endforeach
                </div>
                <div class="text-xs text-ai-secondary space-y-1">
                    @foreach ($anchorData['llm_coverage'] as $llm => $count)
                    <div class="flex justify-between"><span>{{ $llm }}</span><span class="text-ai-dim">{{ $count }} 信息源</span></div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- 最新文章 --}}
            <div class="bento-card p-5">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="text-base font-semibold text-ai-primary">📝 最新文章</h3>
                    <a href="{{ route('client.articles') }}" class="text-xs text-indigo-400 hover:text-indigo-300">全部 →</a>
                </div>
                @if ($articles->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($articles as $article)
                    <div class="border-b border-white/5 pb-3 last:border-0">
                        <div class="text-sm text-ai-primary truncate">{{ $article->title }}</div>
                        <div class="flex gap-3 text-[10px] text-ai-dim mt-1">
                            <span>{{ $article->published_at?->format('m-d') ?? '-' }}</span>
                            <span>{{ $article->keywords ?? '-' }}</span>
                        </div>
                    </div>
                    @endforeach
                </div>
                @else
                <div class="text-center py-4 text-ai-dim text-sm">暂无已发布文章</div>
                @endif
            </div>
        </div>
    </div>

    {{-- 分发平台 Bento Grid --}}
    <div class="bento-card p-5">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-base font-semibold text-ai-primary">📡 分发平台</h3>
            <a href="{{ route('client.platforms') }}" class="text-xs bg-indigo-600/80 text-white px-3 py-1.5 rounded-lg hover:bg-indigo-600 transition font-medium">凭证中心 →</a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
            @foreach ($platforms as $p)
            @php $platform = is_array($p) ? $p : (is_object($p) ? (array)$p : []);
                  $connected = $platform['connected'] ?? false; @endphp
            <div class="rounded-xl border p-3 flex items-center justify-between transition hover:border-indigo-400/20"
                 style="background:rgba(14,16,28,0.5); border-color:{{ $connected ? 'rgba(167,139,250,0.3)' : 'rgba(99,102,241,0.1)' }}">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center text-white text-xs font-bold shrink-0"
                         style="background-color:{{ $platform['color'] ?? '#6b7280' }}">
                        {{ mb_substr($platform['name'] ?? '?', 0, 1) }}
                    </div>
                    <div class="min-w-0">
                        <div class="text-xs text-ai-primary truncate">{{ $platform['name'] ?? '' }}</div>
                        <div class="text-[10px] {{ $connected ? 'text-emerald-400' : 'text-ai-dim' }}">
                            {{ $connected ? '✅ 已授权' : '未授权' }}
                        </div>
                    </div>
                </div>
                @if (!$connected)
                <button onclick="showAuthGuide('{{ $platform['name'] ?? '' }}', '{{ $platform['login_url'] ?? '#' }}')"
                        class="text-[10px] bg-indigo-600/70 text-white px-2 py-1 rounded-lg hover:bg-indigo-600 transition font-medium shrink-0 ml-2">授权</button>
                @endif
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- 授权指引弹窗 — 暗色适配 --}}
<div id="auth-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,0.7); backdrop-filter:blur(4px)" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="rounded-2xl shadow-2xl max-w-lg w-full mx-4 p-6" style="background:rgba(22,24,34,0.95); border:1px solid rgba(255,255,255,0.08)" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <h4 class="text-lg font-bold text-ai-primary" id="auth-modal-title"></h4>
            <button onclick="document.getElementById('auth-modal').classList.add('hidden')" class="text-ai-dim hover:text-white text-xl">&times;</button>
        </div>
        <div class="space-y-4 text-sm">
            <div class="rounded-lg p-3 text-xs" style="background:rgba(251,191,36,0.1); border:1px solid rgba(251,191,36,0.2); color:#fbbf24">
                ⚠️ 如尚未注册该平台账号，请先前往平台官网完成注册。
            </div>
            <ol class="list-decimal ml-4 space-y-2 text-ai-secondary">
                <li>点击下方按钮打开 <span id="auth-platform-name" class="text-ai-primary font-medium"></span></li>
                <li>使用你的账号密码正常登录平台</li>
                <li>登录后通知运营人员"<span id="auth-platform-name2" class="text-ai-primary font-medium"></span> 已授权"</li>
            </ol>
            <a id="auth-platform-link" href="#" target="_blank" rel="noopener noreferrer"
               class="block w-full text-center rounded-xl py-3 font-medium transition"
               style="background:linear-gradient(135deg,#6366f1,#8b5cf6); color:white;">前往平台登录 →</a>
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
