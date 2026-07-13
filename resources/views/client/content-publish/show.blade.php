@extends('client.layout')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-6 space-y-5">
    {{-- 返回 --}}
    <div>
        <a href="{{ route('client.content-publish.index') }}" class="text-sm text-indigo-400 hover:underline">← 返回发布列表</a>
        <h1 class="text-xl font-bold text-ai-primary mt-1">{{ $task->task_name }}</h1>
        <p class="text-sm text-ai-secondary">{{ $task->created_at->format('Y-m-d H:i') }} 提交</p>
    </div>

    {{-- 任务概览卡片 --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <div class="bento-card p-4 text-center">
            <div class="text-2xl font-bold text-ai-primary">{{ $task->total_articles }}</div>
            <div class="text-xs text-ai-dim mt-1">文章数</div>
        </div>
        <div class="bento-card p-4 text-center">
            <div class="text-2xl font-bold text-ai-primary">{{ $task->total_platforms }}</div>
            <div class="text-xs text-ai-dim mt-1">目标平台</div>
        </div>
        <div class="bento-card p-4 text-center">
            <div class="text-2xl font-bold text-ai-primary">{{ $task->total_jobs }}</div>
            <div class="text-xs text-ai-dim mt-1">分发作业</div>
        </div>
        <div class="bento-card p-4 text-center">
            <div class="text-2xl font-bold {{ $task->completed_jobs > 0 ? 'text-emerald-400' : 'text-ai-dim' }}">
                {{ $task->completed_jobs }}
            </div>
            <div class="text-xs text-ai-dim mt-1">已成功</div>
        </div>
        <div class="bento-card p-4 text-center">
            @if ($grade)
            <div class="text-2xl font-bold
                {{ $grade === 'A' ? 'text-emerald-400' : ($grade === 'B' ? 'text-emerald-400' : ($grade === 'C' ? 'text-yellow-600' : 'text-red-400')) }}">
                {{ $grade }}
            </div>
            <div class="text-xs text-ai-dim mt-1">GEO {{ $task->avg_geo_score }}分</div>
            @else
            <div class="text-2xl font-bold text-ai-dim">—</div>
            <div class="text-xs text-ai-dim mt-1">GEO评分</div>
            @endif
        </div>
    </div>

    {{-- 进度条 --}}
    <div class="bento-card p-5">
        <div class="flex justify-between text-sm mb-2">
            <span class="text-ai-secondary">发布进度</span>
            @php $pct = $task->progress_percent ?? 0; @endphp
            <span class="font-medium {{ $task->status === 'completed' ? 'text-emerald-400' : 'text-blue-600' }}">
                {{ $task->completed_jobs }}/{{ $task->total_jobs }} ({{ $pct }}%)
            </span>
        </div>
        <div class="bg-transparent/5 rounded-full h-3">
            <div class="h-3 rounded-full transition-all duration-500
                {{ $task->status === 'completed' ? 'bg-emerald-500/100' : ($task->status === 'failed' ? 'bg-red-500/100' : 'bg-blue-500/100') }}"
                 style="width: {{ max($pct, $pct > 0 ? 5 : 0) }}%"></div>
        </div>
        <div class="flex justify-between text-xs text-ai-dim mt-1">
            <span>成功 {{ $task->completed_jobs }}</span>
            <span>失败 {{ $task->failed_jobs }}</span>
            <span>状态：<span class="font-medium
                {{ $task->status === 'completed' ? 'text-emerald-400' : ($task->status === 'running' ? 'text-blue-600' : ($task->status === 'failed' ? 'text-red-400' : 'text-ai-secondary')) }}">
                {{ $task->status === 'completed' ? '已完成' : ($task->status === 'running' ? '进行中' : ($task->status === 'partial_failed' ? '部分失败' : $task->status)) }}
            </span></span>
        </div>
    </div>

    {{-- GEO 评分明细 --}}
    @if ($task->geo_score_details)
    <div class="bento-card overflow-hidden">
        <div class="border-b px-5 py-3">
            <h2 class="text-sm font-semibold text-ai-primary">📊 GEO 评分明细</h2>
        </div>
        <div class="divide-y divide-indigo-400/5">
            @foreach ($task->geo_score_details as $aid => $detail)
            <div class="flex items-center justify-between px-5 py-3">
                <div class="min-w-0 flex-1 text-sm text-ai-primary truncate">
                    {{ \App\Models\Article::find($aid)?->title ?? '文章 #'.$aid }}
                </div>
                <div class="shrink-0 flex items-center gap-3 ml-3">
                    @if ($detail['enhanced'] ?? false)
                    <span class="text-xs text-indigo-500" title="已自动增强">✨ 已优化</span>
                    @endif
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full
                        {{ ($detail['score'] ?? 0) >= 85 ? 'bg-emerald-100 text-emerald-400' :
                           (($detail['score'] ?? 0) >= 70 ? 'bg-green-100 text-green-700' :
                           (($detail['score'] ?? 0) >= 50 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-400')) }}">
                        {{ $detail['score'] ?? '—' }} · {{ $detail['grade'] ?? '—' }}
                    </span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- 文章发布明细 --}}
    <div class="bento-card overflow-hidden">
        <div class="border-b px-5 py-3">
            <h2 class="text-sm font-semibold text-ai-primary">📋 发布明细（按文章聚合）</h2>
        </div>
        @if (empty($articleResults))
        <div class="py-8 text-center text-sm text-ai-dim">暂无明细</div>
        @else
        <div class="divide-y divide-indigo-400/5">
            @foreach ($articleResults as $aid => $ar)
            <div class="px-5 py-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-sm font-medium text-ai-primary truncate flex-1">
                        {{ $ar['article']->title ?? '文章 #'.$aid }}
                    </div>
                    <div class="shrink-0 flex items-center gap-2 text-xs ml-3">
                        <span class="text-emerald-400">{{ $ar['success_count'] }} 成功</span>
                        @if ($ar['failed_count'] > 0)
                        <span class="text-red-400">{{ $ar['failed_count'] }} 失败</span>
                        @endif
                        @if ($ar['pending_count'] > 0)
                        <span class="text-ai-dim">{{ $ar['pending_count'] }} 等待</span>
                        @endif
                    </div>
                </div>
                {{-- 各平台状态 --}}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-1.5">
                    @foreach ($ar['platforms'] as $pr)
                    <div class="flex items-center gap-1.5 text-xs px-2 py-1 rounded
                        {{ $pr['status'] === 'success' ? 'bg-emerald-500/10' : ($pr['status'] === 'failed' ? 'bg-red-500/10' : 'bg-transparent') }}">
                        <span class="w-1.5 h-1.5 rounded-full shrink-0
                            {{ $pr['status'] === 'success' ? 'bg-emerald-500/100' : ($pr['status'] === 'failed' ? 'bg-red-500/100' : 'bg-gray-300') }}"></span>
                        <span class="text-ai-secondary truncate">{{ $pr['platform_key'] }}</span>
                        @if ($pr['status'] === 'failed' && $pr['error_message'])
                        <span class="text-red-400 cursor-help" title="{{ $pr['error_message'] }}">⚠</span>
                        @endif
                        @if ($pr['remote_article_url'])
                        <a href="{{ $pr['remote_article_url'] }}" target="_blank"
                           class="text-indigo-500 hover:underline ml-auto shrink-0">🔗</a>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endsection
