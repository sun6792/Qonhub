@extends('client.layout')

@section('content')
@php $platforms = \App\Services\GeoFlow\AiVisibilityService::AI_PLATFORMS; @endphp
@php $self = $comparison['self'] ?? []; @endphp
@php $compList = $comparison['competitors'] ?? []; @endphp
@php $platComp = $comparison['platform_comparison'] ?? []; @endphp

<div class="space-y-5">
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('client.ai-visibility') }}" class="text-sm hover:underline" style="color:#a5b4fc">← AI搜索可见度</a>
            <h1 class="text-xl font-bold text-ai-primary mt-1">📊 AI搜索竞争力分析报告</h1>
            <p class="text-sm text-ai-secondary mt-1">品牌 vs 竞品在多平台AI搜索中的对比分析</p>
        </div>
    </div>

    @if (session('success'))
    <div class="rounded-lg px-4 py-3 text-sm" style="background:rgba(52,211,153,0.1); border:1px solid rgba(52,211,153,0.2); color:#6ee7b7">{{ session('success') }}</div>
    @endif

    {{-- 自身品牌 vs 竞品 KPI --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="bento-card p-5" style="border-color:rgba(129,140,248,0.3)">
            <h2 class="text-sm font-semibold mb-3" style="color:#a5b4fc">🏠 {{ $self['brand_name'] ?? '我的品牌' }}</h2>
            <div class="space-y-3">
                <div class="flex justify-between"><span class="text-xs text-ai-secondary">总提及次数</span><span class="text-sm font-bold gradient-ai">{{ $self['total_mentions'] ?? 0 }}</span></div>
                <div class="flex justify-between"><span class="text-xs text-ai-secondary">覆盖平台数</span><span class="text-sm font-bold text-ai-primary">{{ $self['covered_platforms'] ?? 0 }}/12</span></div>
                <div class="flex justify-between"><span class="text-xs text-ai-secondary">TOP3排名占比</span><span class="text-sm font-bold text-ai-primary">{{ $self['top3_share'] ?? 0 }}%</span></div>
                <div class="flex justify-between"><span class="text-xs text-ai-secondary">综合可见度</span><span class="text-sm font-bold" style="color:{{ ($self['visibility_score']??0)>50?'#a5b4fc':'#fbbf24' }}">{{ $self['visibility_score'] ?? 0 }}%</span></div>
            </div>
        </div>

        @foreach ($compList as $comp)
        <div class="bento-card p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-ai-primary">🏢 {{ $comp['brand_name'] }}</h2>
                <form method="POST" action="{{ route('client.competitiveness.delete', $comp['id']) }}" onsubmit="return confirm('确认删除？')">
                    @csrf <button class="text-xs hover:underline" style="color:#fca5a5">删除</button>
                </form>
            </div>
            <div class="space-y-3">
                <div class="flex justify-between"><span class="text-xs text-ai-secondary">总提及</span><span class="text-sm font-bold text-ai-primary">{{ $comp['total_mentions'] }}</span></div>
                <div class="flex justify-between"><span class="text-xs text-ai-secondary">覆盖平台</span><span class="text-sm font-bold text-ai-primary">{{ $comp['covered_platforms'] }}/12</span></div>
                <div class="flex justify-between"><span class="text-xs text-ai-secondary">TOP3占比</span><span class="text-sm font-bold text-ai-primary">{{ $comp['top3_share'] }}%</span></div>
            </div>
        </div>
        @endforeach

        @if (count($compList) < 3)
        <div class="bento-card p-5 flex flex-col items-center justify-center text-center cursor-pointer transition hover:border-indigo-400/40"
             style="border-style:dashed; border-color:rgba(99,102,241,0.15)"
             onclick="document.getElementById('addCompetitorModal').classList.remove('hidden')">
            <div class="text-2xl mb-1" style="color:#6366f1">➕</div>
            <span class="text-sm text-ai-dim">添加竞品品牌</span>
            <span class="text-xs text-ai-dim mt-1">最多3个</span>
        </div>
        @endif
    </div>

    {{-- 平台覆盖对比 --}}
    <div class="bento-card p-5">
        <h2 class="text-base font-semibold text-ai-primary mb-4">📡 平台覆盖对比</h2>
        @if (!empty($platComp))
        <div class="space-y-2">
            @foreach ($platComp as $pc)
            <div class="flex items-center gap-2">
                <span class="text-sm w-20 shrink-0 text-ai-secondary">{{ $pc['icon'] }} {{ $pc['name'] }}</span>
                <div class="flex-1 rounded-full h-2" style="background:rgba(255,255,255,0.05)">
                    <div class="h-2 rounded-full" style="width:{{ min(100,$pc['score']) }}%; background:{{ $pc['color'] }}"></div>
                </div>
                <span class="text-xs text-ai-secondary w-12 text-right">{{ $pc['score'] }}%</span>
                <span class="text-xs text-ai-dim w-10 text-right">{{ $pc['mentioned'] }}次</span>
            </div>
            @endforeach
        </div>
        @else
        <div class="text-center py-6 text-ai-dim">暂无对比数据</div>
        @endif
    </div>

    {{-- 品牌词TOP5 --}}
    <div class="bento-card p-5">
        <h2 class="text-base font-semibold text-ai-primary mb-4">🏆 品牌词 TOP5</h2>
        @if (!empty($top5))
        <div class="space-y-3">
            @foreach ($top5 as $kw)
            <div class="flex items-center gap-3">
                <span class="text-sm font-medium text-ai-primary w-24 truncate">{{ $kw['word'] }}</span>
                <div class="flex-1 rounded-full h-2.5" style="background:rgba(255,255,255,0.06)">
                    <div class="h-2.5 rounded-full" style="width:{{ $kw['share'] }}%; background:linear-gradient(90deg,#6366f1,#8b5cf6)"></div>
                </div>
                <span class="text-xs text-ai-secondary">{{ $kw['count'] }}次 · {{ $kw['share'] }}%</span>
            </div>
            @endforeach
        </div>
        @else
        <div class="text-center py-6 text-ai-dim">暂无品牌词数据</div>
        @endif
    </div>

    {{-- 添加竞品弹窗 — 暗色 --}}
    <div id="addCompetitorModal" class="hidden fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,0.7); backdrop-filter:blur(4px)">
        <div class="rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6" style="background:rgba(22,24,34,0.95); border:1px solid rgba(99,102,241,0.15)">
            <h3 class="text-lg font-bold text-ai-primary mb-4">添加竞品品牌</h3>
            <form method="POST" action="{{ route('client.competitiveness.store') }}">
                @csrf
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs text-ai-secondary mb-1">品牌名称 <span style="color:#fca5a5">*</span></label>
                        <input type="text" name="brand_name" required maxlength="100" placeholder="例如：竞品科技"
                               class="w-full rounded-xl text-sm px-3 py-2.5 text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 transition"
                               style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.15)">
                    </div>
                    <div>
                        <label class="block text-xs text-ai-secondary mb-1">品牌官网（选填）</label>
                        <input type="url" name="brand_website" maxlength="500" placeholder="https://..."
                               class="w-full rounded-xl text-sm px-3 py-2.5 text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 transition"
                               style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.15)">
                    </div>
                </div>
                <div class="flex gap-2 mt-5 justify-end">
                    <button type="button" onclick="document.getElementById('addCompetitorModal').classList.add('hidden')"
                            class="rounded-xl border px-4 py-2 text-sm text-ai-secondary hover:text-white transition"
                            style="border-color:rgba(99,102,241,0.15)">取消</button>
                    <button type="submit" class="rounded-xl px-4 py-2 text-sm font-medium text-white transition"
                            style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">添加</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
