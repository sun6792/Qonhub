@extends('client.layout')

@section('content')
<div class="bg-white rounded-xl shadow-sm p-5">
    <div class="flex justify-between items-center mb-4">
        <h3 class="font-bold text-lg">📝 已发布文章列表</h3>
        <span class="text-sm text-gray-400">共 {{ $articles->total() }} 篇</span>
    </div>

    @if ($articles->isNotEmpty())
    <div class="space-y-4">
        @foreach ($articles as $article)
        <div class="border rounded-lg p-4 hover:bg-gray-50 transition">
            <h4 class="font-medium text-gray-800 text-lg">{{ $article->title }}</h4>
            <p class="text-gray-500 text-sm mt-1">{{ Str::limit(strip_tags($article->excerpt ?? ''), 150) ?: '暂无摘要' }}</p>
            <div class="flex flex-wrap gap-3 mt-2 text-xs text-gray-400">
                <span>📅 发布: {{ $article->published_at?->format('Y-m-d H:i') ?? '-' }}</span>
                @if ($article->keywords)
                <span>🏷️ {{ $article->keywords }}</span>
                @endif
                @if ($article->category)
                <span>📂 {{ $article->category->name }}</span>
                @endif
            </div>
        </div>
        @endforeach
    </div>
    <div class="mt-4">
        {{ $articles->links() }}
    </div>
    @else
    <div class="text-center py-12 text-gray-400">
        <p class="text-4xl mb-3">📭</p>
        <p>暂无已发布文章</p>
        <p class="text-sm mt-1">文章发布后将自动显示在此处</p>
    </div>
    @endif
</div>
@endsection
