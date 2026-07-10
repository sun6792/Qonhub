@extends('admin.layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    {{-- 页面头部：标题左，按钮右 --}}
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">工作空间</h1>
            <p class="mt-1 text-sm text-gray-500">每个工作空间代表一个客户项目，包含独立的文章、任务和分发渠道</p>
        </div>
        <a href="{{ route('admin.workspaces.create') }}"
           class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 transition-colors duration-200 shrink-0 self-start sm:self-auto">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            新建工作空间
        </a>
    </div>

    {{-- 空状态 --}}
    @if ($workspaces->isEmpty())
    <div class="rounded-2xl border border-dashed border-gray-300 bg-white py-16 text-center">
        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-indigo-50">
            <svg class="h-8 w-8 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
        </div>
        <h3 class="text-base font-medium text-gray-900">还没有工作空间</h3>
        <p class="mt-1 text-sm text-gray-500">创建第一个工作空间，开始管理客户 GEO 项目</p>
        <a href="{{ route('admin.workspaces.create') }}" class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors duration-200">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            创建第一个工作空间
        </a>
    </div>
    @else
    {{-- 卡片列表 --}}
    <div class="space-y-4">
        @foreach ($workspaces as $ws)
        @php
            $taskCount = \App\Models\WorkspaceAssignment::where('workspace_id', $ws->id)->where('assignable_type', 'App\\Models\\Task')->count();
            $articleCount = \App\Models\WorkspaceAssignment::where('workspace_id', $ws->id)->where('assignable_type', 'App\\Models\\Article')->count();
            $kbCount = \App\Models\WorkspaceAssignment::where('workspace_id', $ws->id)->where('assignable_type', 'App\\Models\\KnowledgeBase')->count();
            $clientCount = \App\Models\ClientUser::where('workspace_id', $ws->id)->count();
            $avatarBg = match($ws->status) {
                'active' => 'from-indigo-500 to-purple-500',
                'paused' => 'from-amber-400 to-orange-500',
                default  => 'from-gray-400 to-gray-500',
            };
        @endphp

        <a href="{{ route('admin.workspaces.show', $ws->slug) }}"
           class="group block rounded-xl border border-gray-200 bg-white shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 cursor-pointer">

            <div class="flex flex-col lg:flex-row lg:items-center gap-4 p-5">
                {{-- 左侧：图标 + 名称 + 状态 + 企业 --}}
                <div class="flex items-start gap-4 lg:w-72 shrink-0">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br {{ $avatarBg }} text-white text-sm font-bold shadow-sm">
                        {{ mb_substr($ws->name, 0, 1) }}
                    </div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="text-base font-semibold text-gray-900 truncate group-hover:text-indigo-600 transition-colors duration-200">{{ $ws->name }}</h3>
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium shrink-0
                                {{ $ws->status === 'active' ? 'bg-emerald-50 text-emerald-700' : ($ws->status === 'paused' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-600') }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $ws->status === 'active' ? 'bg-emerald-500' : ($ws->status === 'paused' ? 'bg-amber-500' : 'bg-gray-400') }}"></span>
                                {{ $ws->status === 'active' ? '活跃' : ($ws->status === 'paused' ? '暂停' : '归档') }}
                            </span>
                        </div>
                        @if ($ws->client_company_name)
                        <p class="mt-0.5 text-sm text-gray-500 truncate">{{ $ws->client_company_name }}</p>
                        @endif
                    </div>
                </div>

                {{-- 右侧：4 项指标 --}}
                <div class="grid grid-cols-4 flex-1 gap-3">
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-900">{{ $articleCount }}</div>
                        <div class="text-xs text-gray-500 mt-0.5">文章</div>
                    </div>
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-900">{{ $taskCount }}</div>
                        <div class="text-xs text-gray-500 mt-0.5">任务</div>
                    </div>
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-900">{{ $kbCount }}</div>
                        <div class="text-xs text-gray-500 mt-0.5">知识库</div>
                    </div>
                    <div class="text-center">
                        <div class="text-xl font-bold text-gray-900">{{ $clientCount }}</div>
                        <div class="text-xs text-gray-500 mt-0.5">客户</div>
                    </div>
                </div>
            </div>

            {{-- 底部：管理员 + 活动 --}}
            <div class="flex items-center justify-between border-t border-gray-100 px-5 py-2.5 text-xs text-gray-400">
                <span>
                    @if ($isSuperAdmin && $ws->owner)
                    {{ $ws->owner->display_name }}
                    @endif
                </span>
                <span>{{ $ws->last_activity_at ? $ws->last_activity_at->diffForHumans() : '暂无活动' }}</span>
            </div>
        </a>
        @endforeach
    </div>
    @endif
</div>
@endsection
