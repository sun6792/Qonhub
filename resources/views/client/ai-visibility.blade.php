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

    {{-- 12平台覆盖矩阵 --}}
    <div class="bento-card p-5">
        <h2 class="text-base font-semibold text-ai-primary mb-4">📡 平台覆盖矩阵（{{ count($platforms) }} 平台 / PC+移动）</h2>
        @if (!empty($scores))
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach ($platforms as $key => $info)
            @php $d = $scores[$key] ?? ['score' => 0, 'trend' => 'new', 'mentioned' => 0]; @endphp
            <div class="rounded-xl border p-4 transition hover:border-indigo-400/30"
                 style="background:rgba(14,16,28,0.5); border-color:{{ ($d['score']??0) > 50 ? 'rgba(167,139,250,0.3)' : (($d['score']??0) > 20 ? 'rgba(251,191,36,0.2)' : 'rgba(99,102,241,0.08)') }}">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-lg">{{ $info['icon'] }}</span>
                    <span class="text-sm font-medium text-ai-primary">{{ $info['name'] }}</span>
                    <span class="ml-auto text-xs font-bold" style="color:{{ ($d['score']??0) > 50 ? '#a5b4fc' : (($d['score']??0) > 20 ? '#fbbf24' : '#6b7280') }}">
                        {{ round($d['score']) }}%
                    </span>
                </div>
                <div class="rounded-full h-1.5 mb-2" style="background:rgba(255,255,255,0.06)">
                    <div class="h-1.5 rounded-full transition-all" style="width:{{ min(100, $d['score']) }}%; background:{{ $info['color'] }}"></div>
                </div>
                <div class="flex justify-between text-[10px]">
                    <span class="text-ai-dim">提及 {{ $d['mentioned'] }} 次</span>
                    <span style="color:{{ $d['trend']==='up'?'#a5b4fc':($d['trend']==='down'?'#fca5a5':'#6b7280') }}">
                        {{ $d['trend'] === 'up' ? '↗' : ($d['trend'] === 'down' ? '↘' : '→') }}
                    </span>
                </div>
                <div class="flex gap-2 mt-2 pt-2 border-t" style="border-color:rgba(99,102,241,0.08)">
                    @if ($info['pc'] ?? true)
                    <span class="text-[10px] px-1.5 py-0.5 rounded" style="background:rgba(99,102,241,0.1); color:#a5b4fc">💻 PC</span>
                    @endif
                    @if ($info['mobile'] ?? true)
                    <span class="text-[10px] px-1.5 py-0.5 rounded" style="background:rgba(99,102,241,0.1); color:#a5b4fc">📱 移动</span>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        @else
        <div class="text-center py-8 text-ai-dim"><p class="text-3xl mb-2">🔍</p><p>AI引用数据正在收集中，系统将在每日凌晨自动检测</p></div>
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
