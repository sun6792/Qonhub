@extends('client.layout')

@section('content')
@php $platforms = \App\Services\GeoFlow\AiVisibilityService::AI_PLATFORMS; @endphp
@php $scores = $visibilityData['latest_scores'] ?? []; @endphp
@php $trends = $visibilityData['trends'] ?? []; @endphp

<div class="space-y-5">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-ai-primary">🤖 AI搜索可见度</h1>
            <p class="text-sm text-ai-secondary mt-1">品牌在12个国内主流AI平台中的引用情况 · 每{{ $visibilityData['last_checked_at'] ?? '日' }}更新</p>
        </div>
        <a href="{{ route('client.competitiveness') }}" class="inline-flex items-center gap-1 rounded-xl border px-4 py-2 text-sm font-medium transition"
           style="border-color:rgba(129,140,248,0.3); color:#a5b4fc; background:rgba(99,102,241,0.08)">
            📊 竞争力报告
        </a>
    </div>

    {{-- KPI 总览 — Bento 卡片 --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bento-card p-4 text-center">
            <div class="text-2xl font-bold gradient-ai">{{ $overview['total_mentions'] ?? 0 }}</div>
            <div class="text-xs text-ai-dim mt-1">30天总提及</div>
        </div>
        <div class="bento-card p-4 text-center">
            <div class="text-2xl font-bold" style="color:{{ ($overview['covered_platforms']??0)>=6?'#a5b4fc':'#fbbf24' }}">
                {{ $overview['covered_platforms'] ?? 0 }}/{{ $overview['total_platforms'] ?? 12 }}
            </div>
            <div class="text-xs text-ai-dim mt-1">平台覆盖</div>
        </div>
        <div class="bento-card p-4 text-center">
            <div class="text-2xl font-bold gradient-ai">{{ $overview['brand_words'] ?? 0 }}</div>
            <div class="text-xs text-ai-dim mt-1">品牌词数</div>
        </div>
        <div class="bento-card p-4 text-center">
            <div class="text-2xl font-bold" style="color:{{ ($overview['trend_direction']??'flat')==='up'?'#a5b4fc':(($overview['trend_direction']??'flat')==='down'?'#fca5a5':'#9ca3af') }}">
                {{ ($overview['trend_direction'] ?? 'flat') === 'up' ? '↑' : (($overview['trend_direction'] ?? 'flat') === 'down' ? '↓' : '→') }}{{ abs($overview['trend_percent'] ?? 0) }}%
            </div>
            <div class="text-xs text-ai-dim mt-1">较昨日</div>
        </div>
    </div>

    {{-- 12平台覆盖矩阵 — 始终显示，点击可跳转验证 --}}
    <div class="bento-card p-5">
        <h2 class="text-base font-semibold text-ai-primary mb-4">📡 平台覆盖矩阵（{{ count($platforms) }} 平台）<span class="text-xs text-ai-dim ml-2">点击平台名可跳转验证</span></h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach ($platforms as $key => $info)
            @php $d = $scores[$key] ?? ['score' => 0, 'trend' => 'new', 'mentioned' => 0]; @endphp
            <div class="rounded-xl p-3.5 transition-all duration-200"
                 style="background:rgba(255,255,255,0.03); border:1px solid {{ $info['color'] ?? '#6366f1' }}22">
                <div class="flex items-center justify-between mb-2">
                    <a href="{{ $info['url'] ?? '#' }}" target="_blank" rel="noopener"
                       class="flex items-center gap-2 hover:underline cursor-pointer"
                       style="pointer-events:auto; z-index:10; position:relative; color:inherit; text-decoration:none;">
                        <span class="text-base">{{ $info['icon'] }}</span>
                        <span class="text-sm font-medium text-white">{{ $info['name'] }}</span>
                        <span class="text-[10px] text-ai-dim">↗</span>
                    </a>
                    <div class="flex items-center gap-1 text-[10px]" style="pointer-events:none">
                        @if($info['pc'] ?? true)<span class="px-1 rounded" style="background:{{ $info['color'] }}22; color:{{ $info['color'] }}">💻</span>@endif
                        @if($info['mobile'] ?? true)<span class="px-1 rounded" style="background:{{ $info['color'] }}22; color:{{ $info['color'] }}">📱</span>@endif
                    </div>
                </div>
                <div class="text-lg font-bold" style="color:{{ $d['score'] >= 50 ? '#a5b4fc' : ($d['score'] >= 20 ? '#fbbf24' : '#9ca3af') }}">
                    {{ $d['score'] ?? 0 }}%
                </div>
                <div class="text-[10px] mt-1 flex items-center gap-2">
                    <span class="text-ai-dim">提及 {{ $d['mentioned'] ?? 0 }} 次</span>
                    <span class="{{ ($d['trend'] ?? 'flat') === 'up' ? 'text-green-400' : (($d['trend'] ?? 'flat') === 'down' ? 'text-red-400' : 'text-ai-dim') }}">
                        {{ ($d['trend'] ?? 'flat') === 'up' ? '↑' : (($d['trend'] ?? 'flat') === 'down' ? '↓' : '→') }}
                    </span>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- v2.6.0 报表明细表 --}}
    <div class="bento-card p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-ai-primary">📋 检测明细</h2>
            <span class="text-xs text-ai-dim">最近50条 · 点击平台名可跳转验证</span>
        </div>
        @php $recentChecks = \App\Models\AiVisibilityCheck::where('workspace_id', $workspace->id)->orderByDesc('checked_at')->limit(50)->get(); @endphp
        @if($recentChecks->isNotEmpty())
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead><tr class="text-ai-dim border-b" style="border-color:rgba(165,180,252,0.08)">
                    <th class="text-left py-2 px-2">#</th><th class="text-left py-2 px-2">关键词</th><th class="text-left py-2 px-2">平台</th><th class="text-left py-2 px-2">时间</th><th class="text-left py-2 px-2">结果</th><th class="text-left py-2 px-2">快照</th>
                </tr></thead>
                <tbody>
                @foreach($recentChecks as $i => $c)
                @php $pI = $platforms[$c->ai_platform] ?? null; @endphp
                <tr class="border-b hover:bg-white/5" style="border-color:rgba(165,180,252,0.04)">
                    <td class="py-2 px-2 text-ai-dim">{{ $i+1 }}</td>
                    <td class="py-2 px-2 text-ai-primary max-w-[120px] truncate">{{ $c->query_keyword }}</td>
                    <td class="py-2 px-2">@if($pI)<a href="{{ route('client.snapshot', $c->id) }}" target="_blank"
   class="inline-flex items-center gap-1 hover:underline" style="color:{{ $pI['color']??'#6366f1' }};cursor:pointer">{{ $pI['icon'] }} {{ $pI['name'] }} <span class="text-[10px] opacity-50">📋 快照</span></a>@else<span class="text-ai-dim">{{ $c->ai_platform }}</span>@endif</td>
                    <td class="py-2 px-2 text-ai-dim">{{ $c->checked_at?->format('m-d H:i') }}</td>
                    <td class="py-2 px-2">@if($c->mentioned)<span class="px-1.5 py-0.5 rounded-full text-[10px] bg-green-500/15 text-green-300">✅ 收录</span>@else<span class="text-ai-dim text-[10px]">—</span>@endif</td>
                    <td class="py-2 px-2">@if($c->response_snippet)<span class="text-[10px] text-ai-dim cursor-help" title="{{ e($c->response_snippet) }}">💬</span>@endif</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="text-center py-6 text-ai-dim text-sm">暂无检测记录，文章发布后将自动检测</div>
        @endif
    </div>

    {{-- 品牌词TOP5 --}}
    <div class="bento-card p-5">
        <h2 class="text-base font-semibold text-ai-primary mb-4">🏆 品牌词 TOP5 占比（近30天）</h2>
        @if (!empty($top5))
        <div class="space-y-3">
            @foreach ($top5 as $kw)
            <div class="flex items-center gap-3">
                <span class="text-sm font-medium text-ai-primary w-24 truncate">{{ $kw['word'] }}</span>
                <div class="flex-1 rounded-full h-2.5" style="background:rgba(255,255,255,0.06)">
                    <div class="h-2.5 rounded-full" style="width:{{ $kw['share'] }}%; background:linear-gradient(90deg,#6366f1,#8b5cf6)"></div>
                </div>
                <span class="text-xs text-ai-secondary w-20 text-right">{{ $kw['count'] }}次 · {{ $kw['share'] }}%</span>
                <span class="text-xs text-ai-dim">{{ $kw['platforms'] }}平台</span>
            </div>
            @endforeach
        </div>
        @else
        <div class="text-center py-6 text-ai-dim">积累足够数据后将展示品牌词排名</div>
        @endif
    </div>

    {{-- 30天趋势 --}}
    <div class="bento-card p-5">
        <h2 class="text-base font-semibold text-ai-primary mb-4">📈 各平台30天趋势</h2>
        @if (!empty($trends))
        <div class="space-y-3">
            @foreach (array_slice($platforms, 0, 6) as $key => $info)
            @php $dataPoints = $trends[$key] ?? []; $data = $scores[$key] ?? ['score'=>0]; @endphp
            <div class="flex items-center gap-2">
                <span class="text-sm w-20 shrink-0 text-ai-secondary">{{ $info['icon'] }} {{ $info['name'] }}</span>
                <div class="flex-1 flex items-end h-8 gap-px">
                    @foreach (array_slice($dataPoints, -30) as $pt)
                    <div class="flex-1 rounded-t transition-all" style="height:{{ max(4, ($pt['total']??0) > 0 ? (($pt['mentioned']??0) / max($pt['total']??0,1)) * 100 : 0) }}%; background:{{ $info['color'] }}; opacity:{{ ($pt['mentioned']??0) > 0 ? 1 : 0.2 }}" title="{{ $pt['date'] }}: {{ $pt['mentioned'] }}/{{ $pt['total'] }}"></div>
                    @endforeach
                </div>
                <span class="text-[10px] text-ai-dim w-12 text-right shrink-0">{{ $data['score'] ?? 0 }}%</span>
            </div>
            @endforeach
        </div>
        @else
        <div class="text-center py-6 text-ai-dim">趋势数据将在积累足够数据后显示</div>
        @endif
    </div>

    {{-- 监测词 / 收录词 — 对标摘星 running/collected words --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div class="bento-card p-5">
            <h2 class="text-base font-semibold text-ai-primary mb-3">🔄 监测中的关键词</h2>
            @if (!empty($runningWords))
            <div class="space-y-2 max-h-64 overflow-y-auto">
                @foreach ($runningWords as $rw)
                <div class="flex items-center justify-between py-1.5 border-b" style="border-color:rgba(165,180,252,0.06)">
                    <span class="text-sm text-ai-primary">{{ $rw['word'] }}</span>
                    <div class="flex items-center gap-3 text-xs">
                        <span style="color:{{ $rw['mentioned'] > 0 ? '#a5b4fc' : '#6b7280' }}">
                            提及 {{ $rw['mentioned'] }}/{{ $rw['total'] }}
                        </span>
                        <span class="text-ai-dim">{{ $rw['platforms'] }}平台</span>
                        <span class="text-[10px] px-1.5 py-0.5 rounded-full {{ $rw['status'] === 'collected' ? 'bg-indigo-500/15 text-indigo-300' : 'bg-white/5 text-ai-dim' }}">
                            {{ $rw['status'] === 'collected' ? '已收录' : '监测中' }}
                        </span>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="text-center py-6 text-ai-dim text-sm">暂无监测数据，系统将在每日凌晨自动检测</div>
            @endif
        </div>

        <div class="bento-card p-5">
            <h2 class="text-base font-semibold text-ai-primary mb-3">✅ 已收录关键词</h2>
            @if (!empty($collectedWords))
            <div class="space-y-2 max-h-64 overflow-y-auto">
                @foreach ($collectedWords as $cw)
                <div class="flex items-center justify-between py-1.5 border-b" style="border-color:rgba(165,180,252,0.06)">
                    <span class="text-sm text-ai-primary">{{ $cw['word'] }}</span>
                    <div class="flex items-center gap-2 text-xs">
                        <span style="color:#a5b4fc">🔥 {{ $cw['mentions'] }}次</span>
                        <span class="text-ai-dim">{{ $cw['platforms'] }}平台收录</span>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="text-center py-6 text-ai-dim text-sm">暂无收录关键词，持续发布优质内容后会逐步被AI平台收录</div>
            @endif
        </div>
    </div>

    {{-- 📋 系统建议 — Review Agent 产出 --}}
    @php $reviewRecommendations = $snapshotData['review_recommendations'] ?? $reviewRecommendations ?? []; @endphp
    @if(!empty($reviewRecommendations))
    <div class="bento-card p-5" style="background:rgba(22,24,40,0.9); border-color:rgba(165,180,252,0.2)">
        <h2 class="text-base font-semibold mb-3" style="color:#c4b5fd">📋 系统优化建议</h2>
        <div class="space-y-2">
            @foreach($reviewRecommendations as $rec)
            <div class="flex items-start gap-2 text-sm" style="color:rgba(255,255,255,0.7)">
                <span style="color:#a5b4fc">▸</span>
                <span>{{ $rec }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- 💬 实时对话快照 — v2.6.1 新增 --}}
    @if(!empty($snapshots))
    <div class="bento-card p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-ai-primary">💬 实时对话快照</h2>
            <span class="text-xs text-ai-dim">最近 {{ count($snapshots) }} 次AI对话 · 品牌词检测</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            @foreach($snapshots as $s)
            <div class="rounded-xl p-3.5 transition-all duration-200 hover:shadow-lg"
                 style="background:rgba(255,255,255,0.03); border:1px solid {{ $s['color'] }}22; cursor:default">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <span class="text-base">{{ $s['icon'] }}</span>
                        <span class="text-sm font-medium text-white">{{ $s['name'] }}</span>
                        <span class="text-[10px] text-ai-dim">{{ $s['model'] }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($s['mentioned'])
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-medium"
                              style="background:rgba(34,197,94,0.15); color:#4ade80">✓ 已提及</span>
                        @else
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-medium"
                              style="background:rgba(251,191,36,0.15); color:#fbbf24">未提及</span>
                        @endif
                        <span class="text-[10px] text-ai-dim">{{ $s['snapshot_at'] }}</span>
                    </div>
                </div>
                {{-- GEO 评分条 --}}
                <div class="flex items-center gap-2 mb-2">
                    <div class="flex-1 h-1.5 rounded-full" style="background:rgba(255,255,255,0.06)">
                        <div class="h-full rounded-full transition-all duration-700" style="width:{{ $s['score'] }}%; background:{{ $s['score'] >= 70 ? '#4ade80' : ($s['score'] >= 30 ? '#fbbf24' : '#f87171') }}"></div>
                    </div>
                    <span class="text-[10px] font-mono" style="color:{{ $s['score'] >= 70 ? '#4ade80' : ($s['score'] >= 30 ? '#fbbf24' : '#f87171') }}">{{ $s['score'] }}/100</span>
                </div>
                {{-- 回答预览 --}}
                <p class="text-xs leading-relaxed line-clamp-3" style="color:rgba(255,255,255,0.55)">{{ $s['preview'] ?: '(暂无回答)' }}</p>
                @if($s['cited_url_count'] > 0)
                <div class="mt-2 text-[10px]" style="color:{{ $s['color'] }}">
                    🔗 引用 {{ $s['cited_url_count'] }} 个来源
                </div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- 🔗 收录来源 — v2.6.1 新增 --}}
    @if(!empty($citedSources))
    <div class="bento-card p-5">
        <h2 class="text-base font-semibold text-ai-primary mb-4">🔗 AI 收录来源</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            @foreach($citedSources as $cs)
            <a href="{{ $cs['url'] }}" target="_blank" rel="noopener"
               class="rounded-xl p-3 transition-all duration-200 block hover:shadow-md hover:no-underline"
               style="background:rgba(255,255,255,0.03); border:1px solid {{ $cs['platform_color'] }}22">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-xs">{{ $cs['platform_icon'] }}</span>
                    <span class="text-[10px] px-1.5 py-0.5 rounded" style="background:{{ $cs['platform_color'] }}22; color:{{ $cs['platform_color'] }}">{{ $cs['platform_name'] }}</span>
                </div>
                <div class="text-xs font-medium text-white truncate" title="{{ $cs['url'] }}">
                    {{ $cs['title'] ?: $cs['domain'] ?: parse_url($cs['url'], PHP_URL_HOST) }}
                </div>
                <div class="text-[10px] mt-1 truncate" style="color:rgba(255,255,255,0.35)">
                    {{ $cs['domain'] ?: parse_url($cs['url'], PHP_URL_HOST) }}
                </div>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- 提升建议 — 实色底衬不融合 --}}
    <div class="bento-card p-5" style="background:rgba(22,24,40,0.9); border-color:rgba(165,180,252,0.2)">
        <h3 class="font-bold text-sm mb-3" style="color:#ddd6fe">💡 如何提升AI搜索可见度？</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-xs" style="color:#c4b5fd">
            <div>✅ <strong>标题用问句</strong>：AI用户99%用问句提问</div>
            <div>✅ <strong>文中加FAQ</strong>：Q&A格式是AI最爱的引用源</div>
            <div>✅ <strong>数据加来源</strong>：标注后引用权重提升115%</div>
            <div>✅ <strong>结论前置</strong>：段落首句直接说核心结论</div>
            <div>✅ <strong>多平台分发</strong>：覆盖头条、百家号等高引平台</div>
            <div>✅ <strong>B2B锚点</strong>：企业信息锚点提升大模型收录率</div>
        </div>
    </div>
</div>
@endsection
