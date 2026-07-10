@extends('admin.layouts.app')

@section('content')
<div class="max-w-2xl mx-auto px-4 py-6">
    <a href="{{ route('admin.workspaces.index') }}" class="text-sm text-gray-500 hover:text-blue-600 transition mb-4 inline-block">← 返回工作空间</a>
    <h1 class="text-2xl font-bold text-gray-900 mb-6">🏢 新建工作空间</h1>

    <form action="{{ route('admin.workspaces.store') }}" method="POST" class="space-y-6">
        @csrf

        {{-- 基本信息 --}}
        <div class="bg-white rounded-xl border p-6 space-y-4">
            <h2 class="font-semibold text-gray-800">基本信息</h2>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">工作空间名称 <span class="text-red-500">*</span></label>
                <input type="text" name="name" class="w-full rounded-lg border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="如：空发科技-智能物流" required maxlength="120">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">负责人</label>
                <select name="owner_admin_id" class="w-full rounded-lg border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">暂不指定</option>
                    @foreach ($operators as $op)
                    <option value="{{ $op->id }}">{{ $op->display_name }} ({{ $op->username }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">描述</label>
                <textarea name="description" class="w-full rounded-lg border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" rows="2" maxlength="500" placeholder="项目简介或备注"></textarea>
            </div>
        </div>

        {{-- 客户信息 --}}
        <div class="bg-white rounded-xl border p-6 space-y-4">
            <h2 class="font-semibold text-gray-800">👤 客户信息</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">客户企业名称</label>
                    <input type="text" name="client_company_name" class="w-full rounded-lg border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" maxlength="200" placeholder="如：空发（广州）科技有限公司">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">联系人</label>
                    <input type="text" name="client_contact_name" class="w-full rounded-lg border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" maxlength="100" placeholder="如：陈总">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">客户邮箱</label>
                    <input type="email" name="client_email" class="w-full rounded-lg border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" maxlength="200" placeholder="contact@example.com">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">客户电话</label>
                    <input type="text" name="client_phone" class="w-full rounded-lg border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" maxlength="40" placeholder="138xxxx">
                </div>
            </div>
        </div>

        {{-- AI追踪关键词 --}}
        <div class="bg-white rounded-xl border p-6 space-y-4">
            <h2 class="font-semibold text-gray-800">🤖 AI引用追踪关键词</h2>
            <p class="text-xs text-gray-500">这些关键词将用于每日检测品牌在DeepSeek、豆包、文心、Kimi等AI中的引用情况</p>
            <textarea name="brand_keywords" class="w-full rounded-lg border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" rows="4" placeholder="每行一个关键词&#10;如：&#10;智能物流系统&#10;仓储自动化&#10;物流机器人&#10;AGV搬运"></textarea>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-blue-700 transition">创建</button>
            <a href="{{ route('admin.workspaces.index') }}" class="rounded-lg border border-gray-300 px-6 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">取消</a>
        </div>
    </form>
</div>
@endsection
