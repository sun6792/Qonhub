@extends('admin.layouts.app')
@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">发布任务 #{{ $task->id }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $task->task_name }} · 工作空间：{{ $task->workspace?->name ?? '-' }} · 创建于 {{ $task->created_at->format('m-d H:i') }}</p>
        </div>
        <a href="{{ route('admin.content-publish.index', ['workspace_id' => $task->workspace_id]) }}" class="text-sm text-gray-500 hover:text-gray-700">← 返回列表</a>
    </div>

    {{-- 进度 --}}
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-semibold text-gray-700">进度</span>
            <span class="text-xs font-medium px-2 py-1 rounded-full
                {{ $task->status === 'completed' ? 'bg-green-100 text-green-700' : ($task->status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700') }}">
                {{ ['pending'=>'待处理','processing'=>'处理中','completed'=>'完成','partial_failed'=>'部分失败','cancelled'=>'已取消','failed'=>'失败'][$task->status] ?? $task->status }}
            </span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-3 mb-2">
            <div class="h-3 rounded-full {{ $task->status === 'completed' ? 'bg-green-500' : 'bg-blue-500' }}" style="width: {{ $task->progress_percent }}%"></div>
        </div>
        <div class="text-xs text-gray-500">{{ $task->completed_jobs }}/{{ $task->total_jobs }} 完成 · {{ $task->failed_jobs }} 失败</div>
    </div>

    {{-- 作业明细 --}}
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-gray-100 px-5 py-3">
            <h2 class="text-sm font-semibold text-gray-800">📋 发布明细</h2>
        </div>
        @if($task->results->isNotEmpty())
        <div class="divide-y divide-gray-50">
            @foreach($task->results as $result)
            <div class="flex items-center justify-between px-5 py-3 text-sm">
                <div>
                    <span class="font-medium text-gray-800">{{ $result->article?->title ?? '文章#'.$result->article_id }}</span>
                    <span class="text-gray-400 ml-2">→ {{ $result->platform_key ?? '-' }}</span>
                </div>
                <span class="px-2 py-0.5 rounded-full text-xs font-medium
                    {{ $result->status === 'success' ? 'bg-green-100 text-green-700' : ($result->status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600') }}">
                    {{ $result->status === 'success' ? '成功' : ($result->status === 'failed' ? '失败' : $result->status) }}
                </span>
            </div>
            @endforeach
        </div>
        @else
        <div class="py-8 text-center text-sm text-gray-400">暂无明细数据</div>
        @endif
    </div>
</div>
@endsection
