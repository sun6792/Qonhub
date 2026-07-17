@extends('client.layout')

@section('title', '智能体报告 #' . $execution->id)
@section('page_title', '智能体工作流报告')

@section('content')
<div class="max-w-5xl mx-auto space-y-6">

    {{-- 执行摘要卡片 --}}
    <div class="bg-gradient-to-r from-indigo-600 to-violet-600 rounded-2xl p-6 text-white shadow-lg">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-2xl font-bold">工作流 #{{ $execution->id }}</h2>
                <p class="text-indigo-200 text-sm mt-1">执行时间：{{ $execution->started_at?->format('Y-m-d H:i') ?? '-' }}</p>
                @if($iteration > 0)
                <span class="inline-block mt-2 px-3 py-1 bg-white/20 rounded-full text-xs font-medium">第 {{ $iteration + 1 }} 轮迭代</span>
                @endif
            </div>
            <div class="text-right">
                <div class="text-3xl font-bold">{{ $geoScore }}</div>
                <div class="text-sm text-indigo-200">GEO 评分</div>
                <span class="inline-block mt-1 px-3 py-1 rounded-full text-xs font-bold
                    {{ $geoGrade === 'A' || $geoGrade === 'B' ? 'bg-green-400 text-green-900' : '' }}
                    {{ $geoGrade === 'C' || $geoGrade === 'D' ? 'bg-yellow-400 text-yellow-900' : '' }}
                    {{ $geoGrade === 'E' || $geoGrade === 'F' ? 'bg-red-400 text-red-900' : '' }}">
                    等级 {{ $geoGrade }}
                </span>
            </div>
        </div>
        @php $stateLabels = [
            'idle' => '等待中', 'scouting' => '侦察中', 'planning' => '策略规划',
            'writing' => '内容生产', 'deploying' => '分发执行', 'reviewing' => '复盘分析',
            'completed' => '已完成', 'failed' => '失败'
        ]; @endphp
        <p class="text-sm text-indigo-100">当前状态：{{ $stateLabels[$execution->current_state] ?? $execution->current_state }} · {{ $execution->workflow_key }}</p>
    </div>

    {{-- AI 平台收录状态 — 红/黄/绿 --}}
    <div class="bg-gray-900 rounded-2xl p-6 shadow-lg">
        <h3 class="text-lg font-bold text-white mb-4">各大 AI 平台收录状态</h3>
        @if(empty($platformStatuses))
            <p class="text-gray-400 text-sm">暂无实时搜索数据。启动智能体工作流后将自动检测。</p>
        @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($platformStatuses as $p)
            <div class="flex items-start gap-4 p-4 rounded-xl border
                {{ $p['status'] === 'green' ? 'border-green-700 bg-green-950/30' : '' }}
                {{ $p['status'] === 'yellow' ? 'border-yellow-700 bg-yellow-950/30' : '' }}
                {{ $p['status'] === 'red' ? 'border-red-700 bg-red-950/30' : '' }}">
                <div class="w-3 h-3 rounded-full mt-1.5 shrink-0
                    {{ $p['status'] === 'green' ? 'bg-green-400' : '' }}
                    {{ $p['status'] === 'yellow' ? 'bg-yellow-400' : '' }}
                    {{ $p['status'] === 'red' ? 'bg-red-400' : '' }}"></div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-white font-semibold">{{ $p['name'] }}</span>
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium
                            {{ $p['status'] === 'green' ? 'bg-green-800 text-green-300' : '' }}
                            {{ $p['status'] === 'yellow' ? 'bg-yellow-800 text-yellow-300' : '' }}
                            {{ $p['status'] === 'red' ? 'bg-red-800 text-red-300' : '' }}">
                            {{ $p['mentioned'] ? '已收录' : '未收录' }} · {{ $p['score'] }}分
                        </span>
                    </div>
                    @if($p['preview'])
                    <p class="text-gray-400 text-xs line-clamp-2">{{ $p['preview'] }}</p>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- GEO 优化建议 --}}
    @if(!empty($recommendations))
    <div class="bg-white rounded-2xl p-6 shadow-lg border border-gray-100">
        <h3 class="text-lg font-bold text-gray-800 mb-4">优化建议</h3>
        <ul class="space-y-2">
            @foreach($recommendations as $rec)
            <li class="flex items-start gap-3 text-sm text-gray-700">
                <span class="text-indigo-500 mt-0.5">→</span>
                <span>{{ $rec }}</span>
            </li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- 管道阶段进度 --}}
    @if(!empty($summary['phases']))
    <div class="bg-white rounded-2xl p-6 shadow-lg border border-gray-100">
        <h3 class="text-lg font-bold text-gray-800 mb-4">管道阶段</h3>
        <div class="grid grid-cols-5 gap-3">
            @foreach(['scout' => '侦察', 'strategy' => '策略', 'content' => '内容', 'deploy' => '分发', 'review' => '复盘'] as $phase => $label)
            @php $status = $summary['phases'][$phase] ?? 'pending'; @endphp
            <div class="text-center p-3 rounded-xl
                {{ $status === 'completed' ? 'bg-green-50 border border-green-200' : '' }}
                {{ $status === 'skipped' ? 'bg-gray-50 border border-gray-200' : '' }}
                {{ $status !== 'completed' && $status !== 'skipped' ? 'bg-yellow-50 border border-yellow-200' : '' }}">
                <div class="text-xl mb-1">
                    {{ $status === 'completed' ? '✅' : ($status === 'skipped' ? '⏭️' : '⏳') }}
                </div>
                <div class="text-xs font-semibold text-gray-700">{{ $label }}</div>
                <div class="text-xs text-gray-400">{{ $status }}</div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- 分发统计 --}}
    <div class="bg-white rounded-2xl p-6 shadow-lg border border-gray-100">
        <h3 class="text-lg font-bold text-gray-800 mb-4">分发统计</h3>
        <div class="flex gap-8">
            <div class="text-center">
                <div class="text-3xl font-bold text-green-600">{{ $summary['channels_published'] ?? 0 }}</div>
                <div class="text-sm text-gray-500">已发布渠道</div>
            </div>
            <div class="text-center">
                <div class="text-3xl font-bold text-red-500">{{ $summary['channels_failed'] ?? 0 }}</div>
                <div class="text-sm text-gray-500">失败渠道</div>
            </div>
        </div>
    </div>

    <div class="text-center text-sm text-gray-400 pb-8">
        报告生成时间：{{ $execution->completed_at?->format('Y-m-d H:i:s') ?? $execution->updated_at?->format('Y-m-d H:i:s') ?? '-' }}
        · 豆流 AI 智能体管道
    </div>
</div>
@endsection
