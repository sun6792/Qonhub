@extends('admin.layouts.app')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
    <a href="{{ route('admin.workspaces.show', $workspace->slug) }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-indigo-600 transition-colors duration-200 mb-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        返回工作空间
    </a>
    <h1 class="text-xl font-semibold text-gray-900 mb-1">编辑工作空间</h1>

    <form action="{{ route('admin.workspaces.update', $workspace->slug) }}" method="POST" class="space-y-5">
        @csrf
        @method('PUT')

        {{-- 基本信息 --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-6 space-y-4">
            <h2 class="text-sm font-semibold text-gray-800">基本信息</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">名称 <span class="text-red-400">*</span></label>
                    <input name="name" value="{{ old('name', $workspace->name) }}" class="w-full rounded-lg border-gray-300 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500" required maxlength="120">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">状态</label>
                    <select name="status" class="w-full rounded-lg border-gray-300 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="active" {{ $workspace->status === 'active' ? 'selected' : '' }}>活跃</option>
                        <option value="paused" {{ $workspace->status === 'paused' ? 'selected' : '' }}>暂停</option>
                        <option value="archived" {{ $workspace->status === 'archived' ? 'selected' : '' }}>归档</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">描述</label>
                <textarea name="description" rows="2" class="w-full rounded-lg border-gray-300 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500" maxlength="500">{{ old('description', $workspace->description) }}</textarea>
            </div>
        </div>

        {{-- 客户信息 --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-6 space-y-4">
            <h2 class="text-sm font-semibold text-gray-800">客户信息</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">客户企业名称</label>
                    <input name="client_company_name" value="{{ old('client_company_name', $workspace->client_company_name) }}" class="w-full rounded-lg border-gray-300 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500" maxlength="200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">联系人</label>
                    <input name="client_contact_name" value="{{ old('client_contact_name', $workspace->client_contact_name) }}" class="w-full rounded-lg border-gray-300 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500" maxlength="100">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">客户邮箱</label>
                    <input name="client_email" value="{{ old('client_email', $workspace->client_email) }}" type="email" class="w-full rounded-lg border-gray-300 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500" maxlength="200">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">客户电话</label>
                    <input name="client_phone" value="{{ old('client_phone', $workspace->client_phone) }}" class="w-full rounded-lg border-gray-300 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500" maxlength="40">
                </div>
            </div>
        </div>

        {{-- AI 追踪关键词 --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-6 space-y-4">
            <div>
                <h2 class="text-sm font-semibold text-gray-800">AI 引用追踪关键词</h2>
                <p class="text-xs text-gray-500 mt-1">每日自动检测品牌在 DeepSeek、豆包、文心等 AI 中的引用情况</p>
            </div>
            <textarea name="brand_keywords" rows="4" class="w-full rounded-lg border-gray-300 px-3.5 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="每行一个关键词">{{ old('brand_keywords', implode("\n", $workspace->brandKeywordList())) }}</textarea>
        </div>

        {{-- v2.6.0 智能体自动化配置 --}}
        @php $wsConfig = $workspace->config ?? []; @endphp
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-6 space-y-4">
            <div>
                <h2 class="text-sm font-semibold text-gray-800">智能体自动化</h2>
                <p class="text-xs text-gray-500 mt-1">控制五智能体 v2.6.0 的自动化行为</p>
            </div>
            <div class="space-y-3">
                <label class="flex items-center justify-between gap-4 p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition cursor-pointer">
                    <div>
                        <span class="text-sm font-medium text-gray-700">发布后自动收录检测</span>
                        <p class="text-xs text-gray-400 mt-0.5">文章分发完成后，自动在12个AI平台检测品牌收录情况（3/7/15天三轮复测）</p>
                    </div>
                    <input type="hidden" name="auto_deploy_scout" value="0">
                    <input type="checkbox" name="auto_deploy_scout" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" {{ ($wsConfig['auto_deploy_scout'] ?? true) ? 'checked' : '' }}>
                </label>
                <label class="flex items-center justify-between gap-4 p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition cursor-pointer">
                    <div>
                        <span class="text-sm font-medium text-gray-700">自动迭代优化 <span class="text-xs text-orange-500 font-normal ml-1">Beta</span></span>
                        <p class="text-xs text-gray-400 mt-0.5">复盘发现问题时，自动触发新一轮策略优化和内容改写（最多3轮）</p>
                    </div>
                    <input type="hidden" name="auto_optimize_iteration" value="0">
                    <input type="checkbox" name="auto_optimize_iteration" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" {{ ($wsConfig['auto_optimize_iteration'] ?? false) ? 'checked' : '' }}>
                </label>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 transition-colors duration-200">保存修改</button>
            <a href="{{ route('admin.workspaces.show', $workspace->slug) }}" class="rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors duration-200">取消</a>
        </div>
    </form>
</div>
@endsection
