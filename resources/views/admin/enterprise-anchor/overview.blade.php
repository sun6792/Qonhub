@extends('admin.layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
    {{-- 页头 --}}
    <div>
        <h1 class="text-xl font-semibold text-gray-900">🏢 信息锚点总览</h1>
        <p class="mt-1 text-sm text-gray-500">管理企业 B2B 认证，让品牌信息被主流大模型收录引用</p>
    </div>

    {{-- 全局统计 --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        @php
        $statItems = [
            ['value' => $stats['total_workspaces'], 'label' => '活跃工作空间', 'color' => 'text-indigo-600'],
            ['value' => $stats['with_profile'], 'label' => '已有企业档案', 'color' => 'text-emerald-600'],
            ['value' => $stats['total_certified'], 'label' => '已认证平台', 'color' => 'text-blue-600'],
            ['value' => $stats['total_pending'], 'label' => '待认证', 'color' => 'text-amber-600'],
            ['value' => $totalPlatforms, 'label' => '锚点平台', 'color' => 'text-purple-600'],
        ];
        @endphp
        @foreach ($statItems as $item)
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-center shadow-sm">
            <div class="text-2xl font-bold {{ $item['color'] }}">{{ $item['value'] }}</div>
            <div class="text-xs text-gray-500 mt-0.5">{{ $item['label'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- 锚点平台说明 --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-gray-100 px-5 py-3.5">
            <h2 class="text-sm font-semibold text-gray-800">📡 B2B 信息锚点平台</h2>
            <p class="text-xs text-gray-400 mt-0.5">这些平台的企业页面会被主流大模型的训练和检索系统抓取，认证后品牌信息出现在 AI 回答中的概率显著提升。</p>
        </div>
        <div class="px-5 py-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($platforms as $key => $p)
                <div class="rounded-lg border border-gray-200 p-3.5">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-xs font-bold" style="background-color: {{ $p['color'] }}">
                            {{ mb_substr($p['name'], 0, 1) }}
                        </span>
                        <div>
                            <div class="text-sm font-medium text-gray-800">{{ $p['name'] }}</div>
                            <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium
                                {{ $p['citation_weight'] === 'highest' ? 'bg-red-50 text-red-700' : ($p['citation_weight'] === 'high' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-600') }}">
                                {{ $p['citation_weight'] === 'highest' ? '顶级权重' : ($p['citation_weight'] === 'high' ? '高权重' : ($p['citation_weight'] === 'medium' ? '中权重' : '广覆盖')) }}
                            </span>
                        </div>
                    </div>
                    <div class="text-xs text-gray-500 mb-2">{{ $p['description'] }}</div>
                    <div class="text-xs text-gray-400">
                        🤖 引用大模型：{{ implode('、', $p['cited_by_llms']) }}
                    </div>
                    <div class="text-xs text-gray-400 mt-0.5">
                        📋 {{ $p['cert_required'] }}
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- 工作空间锚点状态 --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-gray-100 px-5 py-3.5 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-800">📊 各工作空间锚点认证进度</h2>
            <span class="text-xs text-gray-400">共 {{ count($workspaceData) }} 个</span>
        </div>
        @if (empty($workspaceData))
        <div class="py-12 text-center text-sm text-gray-400">暂无活跃工作空间</div>
        @else
        <div class="divide-y divide-gray-50">
            @foreach ($workspaceData as $row)
            @php $ws = $row['workspace']; $s = $row['summary']; @endphp
            <a href="{{ route('admin.enterprise-anchor.manage', $ws->slug) }}" class="flex flex-col sm:flex-row sm:items-center sm:justify-between px-5 py-4 hover:bg-gray-50/60 transition-colors duration-150">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-gray-800">{{ $ws->name }}</span>
                        @if ($row['has_profile'])
                            @if ($row['profile']->isVerified())
                            <span class="inline-flex items-center gap-0.5 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">✅ 已核验</span>
                            @else
                            <span class="inline-flex items-center gap-0.5 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">📝 待核验</span>
                            @endif
                        @else
                        <span class="inline-flex items-center gap-0.5 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">暂无档案</span>
                        @endif
                    </div>
                    <div class="text-xs text-gray-400 mt-0.5">
                        {{ $row['has_profile'] ? ($row['profile']->company_full_name ?: '未填写公司名称') : '尚未创建企业档案' }}
                    </div>
                </div>
                <div class="flex items-center gap-4 mt-2 sm:mt-0 shrink-0">
                    {{-- 认证进度条 --}}
                    <div class="flex items-center gap-1.5">
                        <div class="w-24 bg-gray-200 rounded-full h-1.5">
                            <div class="h-1.5 rounded-full {{ $s['certified'] === $s['total'] ? 'bg-emerald-500' : ($s['certified'] > 0 ? 'bg-blue-500' : 'bg-gray-300') }}"
                                 style="width: {{ $s['total'] > 0 ? round($s['certified'] / $s['total'] * 100) : 0 }}%"></div>
                        </div>
                        <span class="text-xs font-medium {{ $s['certified'] === $s['total'] ? 'text-emerald-600' : 'text-gray-500' }}">
                            {{ $s['certified'] }}/{{ $s['total'] }}
                        </span>
                    </div>
                    <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </div>
            </a>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endsection
