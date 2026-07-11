@extends('client.layout')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-6 space-y-5">
    <div>
        <a href="{{ route('client.content-publish.index') }}" class="text-sm text-indigo-600 hover:underline">← 返回发布列表</a>
        <h1 class="text-xl font-bold text-gray-900 mt-1">{{ $task->task_name }}</h1>
        <p class="text-sm text-gray-500 mt-1">{{ $task->created_at->format('Y-m-d H:i') }} 提交</p>
    </div>

    {{-- 进度条 --}}
    <div class="bg-white rounded-xl shadow-sm p-5">
        <div class="flex justify-between text-sm mb-2">
            <span class="text-gray-600">发布进度</span>
            <span class="font-medium {{ $task->status === 'completed' ? 'text-emerald-600' : 'text-blue-600' }}">
                {{ $task->completed_jobs }}/{{ $task->total_jobs }} {{ $task->progress_percent }}%
            </span>
        </div>
        <div class="bg-gray-200 rounded-full h-3">
            <div class="h-3 rounded-full {{ $task->status === 'completed' ? 'bg-emerald-500' : ($task->status === 'failed' ? 'bg-red-500' : 'bg-blue-500') }} transition-all duration-500"
                 style="width: {{ max($task->progress_percent, 5) }}%"></div>
        </div>
        <div class="flex justify-between text-xs text-gray-400 mt-1">
            <span>{{ $task->total_articles }} 篇文章</span>
            <span>{{ $task->total_platforms }} 个平台</span>
            <span>成功 {{ $task->completed_jobs }} / 失败 {{ $task->failed_jobs }}</span>
        </div>
    </div>

    {{-- 发布明细 --}}
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="border-b px-5 py-3">
            <h2 class="text-sm font-semibold text-gray-800">发布明细</h2>
        </div>
        @if ($task->results->isEmpty())
        <div class="py-8 text-center text-sm text-gray-400">暂无明细</div>
        @else
        <div class="divide-y divide-gray-50">
            @foreach ($task->results as $r)
            <div class="flex items-center justify-between px-5 py-3">
                <div class="min-w-0 flex-1">
                    <div class="text-sm text-gray-800 truncate">{{ $r->article?->title ?? '文章 #'.$r->article_id }}</div>
                    <div class="text-xs text-gray-400">{{ $r->platform_key }}
                        @if ($r->remote_article_url)
                        · <a href="{{ $r->remote_article_url }}" target="_blank" class="text-indigo-600 hover:underline">查看链接 →</a>
                        @endif
                    </div>
                </div>
                <span class="shrink-0 text-xs font-medium px-2 py-0.5 rounded-full ml-2
                    {{ $r->status === 'success' ? 'bg-emerald-50 text-emerald-700' : ($r->status === 'failed' ? 'bg-red-50 text-red-700' : ($r->status === 'sending' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-500')) }}">
                    {{ $r->status === 'success' ? '已发布' : ($r->status === 'sending' ? '发送中' : ($r->status === 'failed' ? '失败' : '等待中')) }}
                </span>
            </div>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endsection
