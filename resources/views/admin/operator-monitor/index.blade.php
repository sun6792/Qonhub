@extends('admin.layouts.app')

@php $globalTotalTasks = collect($operators)->sum('active_tasks'); @endphp

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    {{-- 页头 + 批量操作 --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">📊 运营监控台</h1>
            <p class="mt-1 text-sm text-gray-500">按运营人员聚合工作空间与产出</p>
        </div>
        <div class="flex gap-2">
            <button onclick="expandAll()" class="text-xs rounded-lg border border-gray-300 px-3 py-1.5 text-gray-600 hover:bg-gray-50">全部展开</button>
            <button onclick="collapseAll()" class="text-xs rounded-lg border border-gray-300 px-3 py-1.5 text-gray-600 hover:bg-gray-50">全部收起</button>
        </div>
    </div>

    {{-- 4 核心指标 --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-indigo-600">{{ $globalStats['total_operators'] }}</div>
            <div class="text-xs text-gray-500">运营人员</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-emerald-600">{{ $globalStats['active_workspaces'] }}<span class="text-sm font-normal text-gray-400">/{{ $globalStats['total_workspaces'] }}</span></div>
            <div class="text-xs text-gray-500">活跃 / 总空间</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-purple-600">{{ $globalStats['total_articles'] }}</div>
            <div class="text-xs text-gray-500">已发布文章</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-amber-600">{{ $globalTotalTasks }}</div>
            <div class="text-xs text-gray-500">进行中任务</div>
        </div>
    </div>

    {{-- 搜索 --}}
    <div class="flex gap-3">
        <div class="relative flex-1 max-w-sm">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" id="searchInput" oninput="filterOperators()" placeholder="搜索姓名、邮箱或工作空间..."
                   class="w-full pl-9 pr-3 py-2 text-sm rounded-lg border border-gray-300 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-200">
        </div>
        <select id="statusFilter" onchange="filterOperators()" class="text-sm rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-200">
            <option value="all">全部空间</option>
            <option value="active">仅活跃</option>
        </select>
        <span id="matchCount" class="self-center text-xs text-gray-400"></span>
    </div>

    {{-- 运营人员卡片 --}}
    @php $avatarColors = ['#4F46E5','#059669','#D97706','#DC2626','#7C3AED','#0891B2','#E11D48','#0D9488','#9333EA','#2563EB']; @endphp

    <div id="operator-list" class="space-y-4">
        @foreach ($operators as $index => $op)
        @php $color = $avatarColors[$op['id'] % count($avatarColors)]; @endphp
        <div class="operator-card rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden"
             data-operator-name="{{ mb_strtolower($op['name']) }} {{ mb_strtolower($op['email']) }}"
             data-operator-id="{{ $op['id'] }}">

            {{-- 一级：运营概览 --}}
            <div onclick="toggleCard(this)" class="flex flex-col sm:flex-row sm:items-center gap-4 px-5 py-4 cursor-pointer hover:bg-gray-50/50 transition-colors select-none">
                {{-- 头像 + 身份 --}}
                <div class="flex items-center gap-3 min-w-0 sm:w-52 shrink-0">
                    <span class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-sm font-bold shrink-0" style="background-color:{{ $color }}">
                        {{ mb_substr($op['name'], 0, 1) }}
                    </span>
                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5">
                            <span class="text-sm font-semibold text-gray-900 truncate">{{ $op['name'] }}</span>
                            @if ($op['is_super'])
                            <span class="shrink-0 rounded-full bg-red-50 px-1.5 py-0.5 text-[10px] font-medium text-red-700">超管</span>
                            @endif
                        </div>
                        <div class="text-xs text-gray-400 truncate">{{ $op['email'] ?: '' }}</div>
                    </div>
                </div>

                {{-- 3 指标 --}}
                <div class="flex items-center gap-6 flex-1 justify-center min-w-0">
                    <div class="text-center"><div class="text-lg font-bold text-indigo-600">{{ $op['workspace_count'] }}</div><div class="text-[10px] text-gray-400">空间</div></div>
                    <div class="text-center"><div class="text-lg font-bold text-emerald-600">{{ $op['total_articles'] }}</div><div class="text-[10px] text-gray-400">文章</div></div>
                    <div class="text-center"><div class="text-lg font-bold text-amber-600">{{ $op['active_tasks'] }}</div><div class="text-[10px] text-gray-400">任务</div></div>
                </div>

                {{-- 右侧 --}}
                <div class="flex items-center gap-3 shrink-0">
                    <a href="{{ route('admin.operator-monitor.detail', $op['id']) }}" onclick="event.stopPropagation()" class="text-xs font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap">详情 →</a>
                    <svg class="arrow-icon w-5 h-5 text-gray-300 transition-transform duration-200"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>

            {{-- 二级：工作空间列表 --}}
            <div class="workspace-list hidden border-t border-gray-100 bg-gray-50/30">
                @if (!empty($op['workspaces']))
                <div class="divide-y divide-gray-100">
                    @foreach ($op['workspaces'] as $ws)
                    <div class="workspace-row flex items-center gap-3 px-5 py-2.5 hover:bg-white/60 transition-colors"
                         data-ws-name="{{ mb_strtolower($ws['name']) }}"
                         data-ws-status="{{ $ws['status'] }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('admin.workspaces.show', $ws['slug']) }}" class="text-sm font-medium text-gray-800 hover:text-indigo-600 truncate">{{ $ws['name'] }}</a>
                                <span class="shrink-0 inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium
                                    {{ $ws['status'] === 'active' ? 'bg-emerald-50 text-emerald-700' : ($ws['status'] === 'paused' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-500') }}">
                                    {{ $ws['status'] === 'active' ? '活跃' : ($ws['status'] === 'paused' ? '暂停' : '归档') }}
                                </span>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 shrink-0 text-xs text-gray-500">
                            <span class="inline-flex items-center gap-1">
                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                <span>{{ $ws['task_count'] }}</span>
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                                <span>{{ $ws['article_count'] }}</span>
                            </span>
                            <span class="text-gray-400 hidden sm:inline">{{ $ws['last_activity'] }}</span>
                        </div>
                        <a href="{{ route('admin.workspaces.show', $ws['slug']) }}" class="shrink-0 text-[10px] rounded border border-gray-300 bg-white px-2 py-1 text-gray-600 hover:bg-gray-50">查看</a>
                    </div>
                    @endforeach
                </div>
                @else
                <div class="px-5 py-6 text-center text-xs text-gray-400">暂无分配的工作空间</div>
                @endif
            </div>
        </div>
        @endforeach
    </div>

    <div id="no-results" class="hidden rounded-xl border border-dashed border-gray-300 py-16 text-center">
        <div class="text-3xl mb-2">🔍</div>
        <p class="text-gray-400 text-sm">没有匹配的运营人员或工作空间</p>
    </div>
</div>
@endsection

@push('scripts')
<script>
// 折叠/展开单张卡片
function toggleCard(headerEl) {
    const card = headerEl.closest('.operator-card');
    const list = card.querySelector('.workspace-list');
    const arrow = headerEl.querySelector('.arrow-icon');
    if (list) list.classList.toggle('hidden');
    if (arrow) arrow.classList.toggle('rotate-180');
}

// 全部展开
function expandAll() {
    document.querySelectorAll('.workspace-list').forEach(el => el.classList.remove('hidden'));
    document.querySelectorAll('.arrow-icon').forEach(el => el.classList.add('rotate-180'));
}

// 全部收起
function collapseAll() {
    document.querySelectorAll('.workspace-list').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.arrow-icon').forEach(el => el.classList.remove('rotate-180'));
}

// 搜索 + 筛选
function filterOperators() {
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    const statusFilter = document.getElementById('statusFilter').value;
    let visibleCards = 0;

    document.querySelectorAll('.operator-card').forEach(card => {
        // 先按状态筛选工作空间行
        card.querySelectorAll('.workspace-row').forEach(row => {
            if (statusFilter === 'all') { row.style.display = ''; return; }
            row.style.display = row.dataset.wsStatus === statusFilter ? '' : 'none';
        });
        // 再按搜索词筛选
        if (!q) { card.style.display = ''; visibleCards++; return; }
        const cardText = card.dataset.operatorName || '';
        const wsNames = Array.from(card.querySelectorAll('.workspace-row'))
            .map(r => r.dataset.wsName || '').join(' ');
        if (cardText.includes(q) || wsNames.includes(q)) { card.style.display = ''; visibleCards++; }
        else { card.style.display = 'none'; }
    });

    document.getElementById('no-results').classList.toggle('hidden', visibleCards > 0);
    document.getElementById('operator-list').classList.toggle('hidden', visibleCards === 0);
    document.getElementById('matchCount').textContent = visibleCards + ' 个匹配';
}

// 初始化匹配数
document.addEventListener('DOMContentLoaded', () => {
    const count = document.querySelectorAll('.operator-card').length;
    document.getElementById('matchCount').textContent = count + ' 个匹配';
});
</script>
@endpush
