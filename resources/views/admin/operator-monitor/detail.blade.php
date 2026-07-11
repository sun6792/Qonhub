@extends('admin.layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    {{-- 面包屑 --}}
    <div>
        <a href="{{ route('admin.operator-monitor.index') }}" class="text-sm text-gray-500 hover:text-indigo-600 transition-colors">← 运营监控台</a>
        <h1 class="text-xl font-semibold text-gray-900 mt-1">{{ $operator->name }} · 运营详情</h1>
    </div>

    {{-- 两栏：基本信息 + 统计 --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        {{-- 基本信息 --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
            <h2 class="text-sm font-semibold text-gray-800 mb-3">👤 基本信息</h2>
            <div class="grid grid-cols-2 gap-y-2 text-sm">
                <div><span class="text-gray-400">姓名</span><div class="font-medium text-gray-900">{{ $operator->name }}</div></div>
                <div><span class="text-gray-400">账号</span><div class="font-medium text-gray-900">{{ $operator->username }}</div></div>
                <div><span class="text-gray-400">邮箱</span><div class="text-gray-700">{{ $operator->email ?: '—' }}</div></div>
                <div><span class="text-gray-400">角色</span><div>
                    @if ($operator->isSuperAdmin())
                    <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">超级管理员</span>
                    @else
                    <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">运营人员</span>
                    @endif
                </div></div>
                <div><span class="text-gray-400">状态</span><div>
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $operator->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $operator->status === 'active' ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                        {{ $operator->status === 'active' ? '活跃' : '禁用' }}
                    </span>
                </div></div>
                <div><span class="text-gray-400">创建时间</span><div class="text-gray-700">{{ $operator->created_at?->format('Y-m-d') ?: '—' }}</div></div>
            </div>
        </div>

        {{-- 汇总统计 --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
            <h2 class="text-sm font-semibold text-gray-800 mb-3">📊 工作统计</h2>
            <div class="grid grid-cols-3 gap-3 text-center">
                @php
                    $totalTasks = $workspaces->sum('task_count');
                    $totalArticles = $workspaces->sum('article_count');
                    $activeCount = $workspaces->where('status', 'active')->count();
                @endphp
                <div class="rounded-lg bg-indigo-50 p-3">
                    <div class="text-xl font-bold text-indigo-600">{{ $workspaces->count() }}</div>
                    <div class="text-[11px] text-indigo-400">负责空间</div>
                    <div class="text-xs text-gray-400 mt-0.5">{{ $activeCount }} 活跃</div>
                </div>
                <div class="rounded-lg bg-emerald-50 p-3">
                    <div class="text-xl font-bold text-emerald-600">{{ $totalArticles }}</div>
                    <div class="text-[11px] text-emerald-400">关联文章</div>
                </div>
                <div class="rounded-lg bg-amber-50 p-3">
                    <div class="text-xl font-bold text-amber-600">{{ $totalTasks }}</div>
                    <div class="text-[11px] text-amber-400">关联任务</div>
                </div>
            </div>
        </div>
    </div>

    {{-- 工作空间列表 --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
            <h2 class="text-sm font-semibold text-gray-800">🏢 工作空间</h2>
            <span class="text-xs text-gray-400">{{ $workspaces->count() }} 个</span>
        </div>
        @if ($workspaces->isNotEmpty())
        <div class="divide-y divide-gray-50">
            @foreach ($workspaces as $ws)
            <div class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50/60 transition-colors">
                {{-- 名称 --}}
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <a href="{{ route('admin.workspaces.show', $ws->slug) }}" class="text-sm font-medium text-gray-800 hover:text-indigo-600 truncate">{{ $ws->name }}</a>
                        <span class="shrink-0 inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium
                            {{ $ws->status === 'active' ? 'bg-emerald-50 text-emerald-700' : ($ws->status === 'paused' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-500') }}">
                            {{ $ws->status === 'active' ? '活跃' : ($ws->status === 'paused' ? '暂停' : '归档') }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-400 mt-0.5">slug: {{ $ws->slug }}</div>
                </div>

                {{-- 指标 --}}
                <div class="flex items-center gap-4 shrink-0 text-xs text-gray-500">
                    <span class="inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        <span>{{ $ws->task_count }}</span>
                    </span>
                    <span class="inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                        <span>{{ $ws->article_count }}</span>
                    </span>
                    <span class="text-gray-400 hidden sm:inline">{{ $ws->last_activity_at?->diffForHumans() ?: '—' }}</span>
                </div>

                {{-- 按钮 --}}
                <a href="{{ route('admin.workspaces.show', $ws->slug) }}" class="shrink-0 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">查看 →</a>
            </div>
            @endforeach
        </div>
        @else
        <div class="py-10 text-center text-sm text-gray-400">暂无分配的工作空间</div>
        @endif
    </div>
</div>
@endsection
