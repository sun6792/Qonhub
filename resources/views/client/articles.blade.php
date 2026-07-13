@extends('client.layout')

@section('content')
<div class="bento-card p-5">
    <div class="flex justify-between items-center mb-4">
        <h3 class="text-base font-semibold text-ai-primary">📝 已发布文章列表</h3>
        <span class="text-xs text-ai-dim">共 {{ method_exists($articles, 'total') ? $articles->total() : $articles->count() }} 篇</span>
    </div>

    @if ($articles->isNotEmpty())
    <div class="space-y-3">
        @foreach ($articles as $article)
        <div class="rounded-xl border p-4 transition hover:border-indigo-400/20"
             style="background:rgba(14,16,28,0.5); border-color:rgba(99,102,241,0.08)">
            <h4 class="font-medium text-ai-primary">{{ $article->title }}</h4>
            <p class="text-ai-secondary text-sm mt-1">{{ Str::limit(strip_tags($article->excerpt ?? ''), 150) ?: '暂无摘要' }}</p>
            <div class="flex flex-wrap gap-3 mt-2 text-xs text-ai-dim">
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
    <div class="text-center py-12 text-ai-dim">
        <p class="text-4xl mb-3">📭</p>
        <p>暂无已发布文章</p>
        <p class="text-sm mt-1">文章发布后将自动显示在此处</p>
    </div>
    @endif
</div>
@endsection
