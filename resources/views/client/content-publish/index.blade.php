@extends('client.layout')

@section('content')
<div class="max-w-6xl mx-auto px-4 py-6 space-y-5">
    {{-- 页头 --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-ai-primary">📡 我的发布</h1>
            <p class="text-sm text-ai-secondary mt-1">查看已提交的发布任务与分发结果</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('client.content-publish.certify') }}"
               class="inline-flex items-center gap-1 rounded-lg border border-gray-600 px-4 py-2 text-sm font-medium text-ai-primary hover:bg-indigo-50/5">
                🏢 B2B认证
            </a>
            <a href="{{ route('client.content-publish.create') }}"
               class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                ✚ 新建发布
            </a>
        </div>
    </div>

    {{-- 提示消息 --}}
    @if (session('success'))
    <div class="rounded-lg bg-emerald-500/10 border border-emerald-200 px-4 py-3 text-sm text-emerald-400">
        {{ session('success') }}
    </div>
    @endif
    @if (session('warnings'))
    <div class="rounded-lg bg-amber-500/10 border border-amber-200 px-4 py-3 text-sm text-amber-400 space-y-1">
        @foreach (session('warnings') as $w)
        <div>⚠️ {{ $w }}</div>
        @endforeach
    </div>
    @endif

    {{-- 筛选栏 --}}
    <form method="GET" class="bento-card p-4">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <div>
                <label class="block text-xs text-ai-secondary mb-1">任务名称</label>
                <input type="text" name="task_name" value="{{ $filters['task_name'] ?? '' }}"
                       placeholder="搜索任务..." class="w-full rounded-lg text-sm text-white placeholder-gray-500 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-xs text-ai-secondary mb-1">状态</label>
                <select name="status" class="w-full rounded-lg text-sm text-white placeholder-gray-500 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach ($statusOptions as $val => $label)
                    <option value="{{ $val }}" {{ ($filters['status'] ?? '') === (string)$val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-ai-secondary mb-1">开始日期</label>
                <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                       class="w-full rounded-lg text-sm text-white placeholder-gray-500 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-xs text-ai-secondary mb-1">结束日期</label>
                <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                       class="w-full rounded-lg text-sm text-white placeholder-gray-500 px-3 py-2 focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    筛选
                </button>
                <a href="{{ route('client.content-publish.index') }}" class="rounded-lg border border-gray-600 px-3 py-2 text-sm text-ai-secondary hover:bg-indigo-50/5">
                    重置
                </a>
            </div>
        </div>
    </form>

    {{-- 任务列表 --}}
    @if ($tasks->isEmpty())
    <div class="bento-card p-12 text-center">
        <div class="text-4xl mb-3">📭</div>
        <p class="text-ai-secondary">暂无发布任务</p>
        <a href="{{ route('client.content-publish.create') }}" class="inline-block mt-3 text-indigo-400 hover:underline text-sm">去创建第一个发布 →</a>
    </div>
    @else
    <div class="bento-card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-transparent text-ai-secondary">
                <tr>
                    <th class="px-4 py-3 text-left font-medium w-12">#</th>
                    <th class="px-4 py-3 text-left font-medium">任务名称</th>
                    <th class="px-4 py-3 text-center font-medium w-20">文章</th>
                    <th class="px-4 py-3 text-center font-medium w-20">平台</th>
                    <th class="px-4 py-3 text-center font-medium w-24">进度</th>
                    <th class="px-4 py-3 text-center font-medium w-20">GEO评分</th>
                    <th class="px-4 py-3 text-center font-medium w-20">状态</th>
                    <th class="px-4 py-3 text-center font-medium w-28">创建时间</th>
                    <th class="px-4 py-3 text-center font-medium w-16">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-indigo-400/5">
                @foreach ($tasks as $i => $task)
                <tr class="hover:bg-indigo-50/5 cursor-pointer"
                    onclick="window.location='{{ route('client.content-publish.show', $task->id) }}'">
                    <td class="px-4 py-3 text-ai-dim">{{ $tasks->firstItem() + $i }}</td>
                    <td class="px-4 py-3 font-medium text-ai-primary max-w-[200px] truncate">
                        {{ $task->task_name }}
                    </td>
                    <td class="px-4 py-3 text-center text-ai-secondary">{{ $task->total_articles }}</td>
                    <td class="px-4 py-3 text-center text-ai-secondary">{{ $task->total_platforms }}</td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex items-center justify-center gap-1 text-xs">
                            @php $pct = $task->progress_percent ?? 0; @endphp
                            <div class="w-14 bg-transparent/5 rounded-full h-1.5">
                                <div class="h-1.5 rounded-full {{ $task->status === 'completed' ? 'bg-emerald-500' : 'bg-blue-500' }}"
                                     style="width: {{ max($pct, $pct > 0 ? 5 : 0) }}%"></div>
                            </div>
                            <span class="text-ai-dim">{{ $pct }}%</span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if ($task->avg_geo_score !== null)
                        <span class="inline-flex items-center gap-0.5 text-xs font-bold px-1.5 py-0.5 rounded
                            {{ ($task->avg_geo_score) >= 85 ? 'bg-emerald-100 text-emerald-400' :
                               (($task->avg_geo_score) >= 70 ? 'bg-green-100 text-green-700' :
                               (($task->avg_geo_score) >= 50 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-400')) }}">
                            {{ $task->avg_geo_score }}
                        </span>
                        @else
                        <span class="text-ai-dim">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-center">
                        @php
                        $statusMap = [
                            'pending' => ['label' => '待处理', 'class' => 'bg-transparent/5 text-ai-secondary'],
                            'queued' => ['label' => '排队中', 'class' => 'bg-purple-100 text-purple-700'],
                            'running' => ['label' => '进行中', 'class' => 'bg-blue-100 text-blue-700'],
                            'completed' => ['label' => '已完成', 'class' => 'bg-emerald-100 text-emerald-400'],
                            'partial_failed' => ['label' => '部分失败', 'class' => 'bg-amber-100 text-amber-400'],
                            'failed' => ['label' => '失败', 'class' => 'bg-red-100 text-red-400'],
                            'cancelled' => ['label' => '已取消', 'class' => 'bg-transparent/5 text-ai-secondary'],
                        ];
                        $s = $statusMap[$task->status] ?? ['label' => $task->status, 'class' => 'bg-transparent/5 text-ai-secondary'];
                        @endphp
                        <span class="text-xs px-2 py-0.5 rounded-full {{ $s['class'] }}">{{ $s['label'] }}</span>
                    </td>
                    <td class="px-4 py-3 text-center text-xs text-ai-dim">
                        {{ $task->created_at->format('m-d H:i') }}
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="{{ route('client.content-publish.show', $task->id) }}"
                           class="text-indigo-400 hover:underline text-xs">详情</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- 分页 --}}
    <div class="mt-4">
        {{ $tasks->links() }}
    </div>
    @endif
</div>
@endsection
