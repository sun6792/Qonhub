@extends('client.layout')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-6 space-y-5">
    <div>
        <a href="{{ route('client.content-publish.index') }}" class="text-sm text-indigo-600 hover:underline">← 返回发布列表</a>
        <h1 class="text-xl font-bold text-gray-900 mt-1">新建发布</h1>
        <p class="text-sm text-gray-500 mt-1">选择要发布的文章和目标平台</p>
    </div>

    <form method="POST" action="{{ route('client.content-publish.store') }}" class="space-y-5">
        @csrf

        {{-- 选择文章 --}}
        <div class="bg-white rounded-xl shadow-sm p-5">
            <h2 class="text-sm font-semibold text-gray-800 mb-3">📝 选择文章（可多选）</h2>
            @if ($articles->isEmpty())
            <p class="text-sm text-gray-400">暂无可发布的文章，请先等待运营团队发布文章</p>
            @else
            <div class="space-y-2 max-h-64 overflow-y-auto">
                @foreach ($articles as $article)
                <label class="flex items-start gap-3 p-3 rounded-lg border hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" name="article_ids[]" value="{{ $article->id }}" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <div class="min-w-0">
                        <div class="text-sm font-medium text-gray-800 truncate">{{ $article->title }}</div>
                        <div class="text-xs text-gray-400 mt-0.5">{{ $article->published_at?->format('Y-m-d') ?? '-' }} · {{ $article->keywords ?? '无关键词' }}</div>
                    </div>
                </label>
                @endforeach
            </div>
            @endif
        </div>

        {{-- 选择平台 --}}
        <div class="bg-white rounded-xl shadow-sm p-5">
            <h2 class="text-sm font-semibold text-gray-800 mb-3">📡 选择目标平台（可多选）</h2>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                @foreach (collect($platforms)->take(36) as $key => $p)
                <label class="flex items-center gap-2 p-2.5 rounded-lg border hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" name="platform_keys[]" value="{{ $key }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <span class="text-xs text-gray-700">{{ $p['name'] }}</span>
                </label>
                @endforeach
            </div>
        </div>

        <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-3 text-sm font-medium text-white hover:bg-indigo-700">
            🚀 一键发布到所选平台
        </button>
        <p class="text-xs text-gray-400 text-center">发布后将由运营团队代为执行，结果实时更新</p>
    </form>
</div>
@endsection
