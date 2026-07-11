@extends('client.layout')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-6 space-y-5">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-900">📡 我的发布</h1>
            <p class="text-sm text-gray-500 mt-1">查看已提交的发布任务与结果</p>
        </div>
        <a href="{{ route('client.content-publish.create') }}" class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
            ✚ 新建发布
        </a>
    </div>

    @if (session('success'))
    <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">{{ session('success') }}</div>
    @endif

    @if ($tasks->isEmpty())
    <div class="bg-white rounded-xl shadow-sm p-12 text-center">
        <div class="text-4xl mb-3">📭</div>
        <p class="text-gray-500">暂无发布任务</p>
        <a href="{{ route('client.content-publish.create') }}" class="inline-block mt-3 text-indigo-600 hover:underline text-sm">去创建第一个发布 →</a>
    </div>
    @else
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        @foreach ($tasks as $task)
        <a href="{{ route('client.content-publish.show', $task->id) }}" class="flex items-center justify-between px-5 py-4 hover:bg-gray-50 border-b last:border-0">
            <div class="min-w-0 flex-1">
                <div class="text-sm font-medium text-gray-800">{{ $task->task_name }}</div>
                <div class="text-xs text-gray-400 mt-0.5">
                    {{ $task->total_articles }} 篇文章 · {{ $task->total_platforms }} 个平台 · {{ $task->created_at->format('m-d H:i') }}
                </div>
            </div>
            <div class="shrink-0 flex items-center gap-3 ml-3">
                <div class="text-xs text-gray-500">{{ $task->completed_jobs }}/{{ $task->total_jobs }}</div>
                <span class="text-xs font-medium px-2 py-0.5 rounded-full
                    {{ $task->status === 'completed' ? 'bg-emerald-50 text-emerald-700' : ($task->status === 'failed' ? 'bg-red-50 text-red-700' : ($task->status === 'running' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600')) }}">
                    {{ $task->status === 'completed' ? '已完成' : ($task->status === 'running' ? '进行中' : ($task->status === 'partial_failed' ? '部分失败' : $task->status)) }}
                </span>
            </div>
        </a>
        @endforeach
    </div>
    @endif
</div>
@endsection
