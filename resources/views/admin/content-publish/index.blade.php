@extends('admin.layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    <div>
        <h1 class="text-xl font-semibold text-gray-900">📡 全渠道分发运营台</h1>
        <p class="mt-1 text-sm text-gray-500">自媒体 + 新闻媒体 + B2B 全渠道一键发布，复用内容弹药库与锚点体系</p>
    </div>

    {{-- 工作空间选择器 --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-4">
        <form method="GET" action="{{ route('admin.content-publish.index') }}" class="flex items-center gap-3">
            <label class="text-sm font-medium text-gray-600">选择客户：</label>
            <select name="workspace_id" onchange="this.form.submit()" class="rounded-lg border-gray-300 text-sm min-w-[200px]">
                <option value="">-- 全部客户 --</option>
                @php
                    $admin = auth('admin')->user();
                    $isSuperAdmin = $admin && $admin->isSuperAdmin();
                    $wsQuery = \App\Models\Workspace::query()->where('status', 'active')->orderBy('name');
                    if (!$isSuperAdmin) {
                        $wsIds = $admin->scopedWorkspaceIds();
                        if ($wsIds === []) { $wsQuery->whereRaw('1=0'); }
                        elseif ($wsIds !== null) { $wsQuery->whereIn('id', $wsIds); }
                    }
                    $workspaces = $wsQuery->get();
                @endphp
                @foreach($workspaces as $ws)
                <option value="{{ $ws->id }}" {{ ($workspace->id ?? 0) === (int)$ws->id ? 'selected' : '' }}>{{ $ws->name }}</option>
                @endforeach
            </select>
            @if($workspace)
            <a href="{{ route('admin.content-publish.index') }}" class="text-xs text-gray-400 hover:text-gray-600">清除筛选</a>
            @endif
        </form>
    </div>

    {{-- 快速统计 --}}
    @if ($accountStats)
    <div class="grid grid-cols-3 gap-3">
        <div class="rounded-lg border border-gray-200 bg-white p-3 text-center"><div class="text-lg font-bold text-blue-600">{{ $accountStats['self_media'] }}</div><div class="text-xs text-gray-400">自媒体账号</div></div>
        <div class="rounded-lg border border-gray-200 bg-white p-3 text-center"><div class="text-lg font-bold text-purple-600">{{ $accountStats['news_media'] }}</div><div class="text-xs text-gray-400">媒体渠道</div></div>
        <div class="rounded-lg border border-gray-200 bg-white p-3 text-center"><div class="text-lg font-bold text-orange-600">{{ $accountStats['b2b'] }}</div><div class="text-xs text-gray-400">B2B账号</div></div>
    </div>
    @else
    <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-center text-sm text-gray-500">
        👆 请先在上方选择一个客户，才能查看该客户的发布账号统计
    </div>
    @endif

    {{-- 发布任务历史 --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-gray-100 px-5 py-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-800">📋 发布任务</h2>
            <span class="text-xs text-gray-400">{{ $recentTasks->count() }} 条记录</span>
        </div>
        @if ($recentTasks->isNotEmpty())
        <div class="divide-y divide-gray-50">
            @foreach ($recentTasks as $task)
            <div class="flex items-center justify-between px-5 py-3 hover:bg-gray-50/60 group">
                <a href="{{ route('admin.content-publish.task', $task->id) }}" class="min-w-0 flex-1">
                    <div class="text-sm font-medium text-gray-800">{{ $task->task_name ?: '发布任务 #'.$task->id }}</div>
                    <div class="text-xs text-gray-400">{{ $task->completed_jobs }}/{{ $task->total_jobs }} · {{ $task->created_at->format('m-d H:i') }} · {{ $task->workspace?->name ?? '-' }}</div>
                </a>
                <div class="shrink-0 flex items-center gap-3">
                    <div class="w-20 bg-gray-200 rounded-full h-1.5">
                        <div class="h-1.5 rounded-full {{ $task->status === 'completed' ? 'bg-emerald-500' : 'bg-blue-500' }}"
                             style="width: {{ $task->progress_percent }}%"></div>
                    </div>
                    <span class="text-xs font-medium
                        {{ $task->status === 'completed' ? 'text-emerald-600' : ($task->status === 'failed' ? 'text-red-600' : 'text-blue-600') }}">
                        {{ $task->status === 'completed' ? '完成' : ($task->status === 'partial_failed' ? '部分失败' : $task->progress_percent.'%') }}
                    </span>
                    {{-- 操作按钮 --}}
                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('admin.content-publish.delete', $task->id) }}" onsubmit="return confirm('确认删除此发布任务？')">
                            @csrf
                            <button type="submit" class="text-xs text-red-400 hover:text-red-600 font-medium border border-red-200 rounded px-2 py-0.5 hover:bg-red-50">🗑 删除</button>
                        </form>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        @else
        <div class="py-12 text-center text-sm text-gray-400">
            @if($workspace)
            该客户暂无发布任务。<br>
            <a href="{{ route('admin.distribution.armory') }}" class="text-indigo-600 hover:underline">去内容弹药库 → 选文章 → 选模板改写 → 发布</a>
            @else
            暂无发布任务。<br>👆 请先选择上方客户查看，或 <a href="{{ route('admin.distribution.armory') }}" class="text-indigo-600 hover:underline">去内容弹药库</a> 发起一次发布。
            @endif
        </div>
        @endif
    </div>

    {{-- 平台覆盖总览 --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-gray-100 px-5 py-3">
            <h2 class="text-sm font-semibold text-gray-800">🌐 全渠道覆盖总览（{{ count($platforms) }}个平台）</h2>
        </div>
        <div class="px-5 py-3">
            <div class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-9 gap-1.5">
                @foreach (collect($platforms)->take(36) as $key => $p)
                <span class="text-[10px] rounded px-1.5 py-0.5 text-center truncate
                    {{ ($p['type'] ?? '') === 'news_media' ? 'bg-purple-50 text-purple-700' : (($p['type'] ?? '') === 'industry_media' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600') }}"
                    title="{{ $p['name'] }} · {{ $p['citation_weight'] === 'highest' ? '顶级' : '高' }}权重">
                    {{ $p['name'] }}
                </span>
                @endforeach
                @if (count($platforms) > 36)
                <span class="text-[10px] text-gray-400 self-center">+{{ count($platforms) - 36 }}</span>
                @endif
            </div>
            <div class="mt-3 text-xs text-gray-400">
                💡 需先在对应平台注册账号并录入「内容发布账号池」，才能使用一键发布功能。
                <a href="#" class="text-indigo-600 hover:underline">前往账号管理 →</a>
            </div>
        </div>
    </div>
</div>
@endsection
