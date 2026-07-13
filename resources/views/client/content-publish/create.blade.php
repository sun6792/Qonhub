@extends('client.layout')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-6 space-y-5">
    {{-- 页头 --}}
    <div>
        <a href="{{ route('client.content-publish.index') }}" class="text-sm text-indigo-400 hover:underline">← 返回发布列表</a>
        <h1 class="text-xl font-bold text-ai-primary mt-1">新建发布</h1>
        <p class="text-sm text-ai-secondary mt-1">选择要发布的文章和目标平台，提交后系统自动分发</p>
    </div>

    <form method="POST" action="{{ route('client.content-publish.store') }}" id="publishForm">
        @csrf

        {{-- Step 1: 选择文章 --}}
        <div class="bento-card p-5">
            <h2 class="text-sm font-semibold text-ai-primary mb-1">📝 第一步：选择文章 <span class="text-red-400">*</span></h2>
            <p class="text-xs text-ai-dim mb-4">勾选要分发的文章（可多选），GEO评分低于70分的文章提交时自动优化</p>
            @if ($articles->isEmpty())
            <div class="text-center py-8 text-ai-dim">
                <div class="text-3xl mb-2">📄</div>
                <p>暂无可发布的文章，请先等待运营团队发布文章</p>
            </div>
            @else
            <div class="space-y-2 max-h-72 overflow-y-auto border rounded-lg p-2">
                @foreach ($articles as $article)
                <label class="flex items-start gap-3 p-3 rounded-lg border hover:bg-indigo-50/5 cursor-pointer transition">
                    <input type="checkbox" name="article_ids[]" value="{{ $article->id }}"
                           class="article-check mt-0.5 rounded border-gray-600 text-indigo-400 focus:ring-indigo-500">
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium text-ai-primary truncate">{{ $article->title }}</div>
                        <div class="flex items-center gap-3 text-xs text-ai-dim mt-0.5">
                            <span>{{ $article->published_at?->format('Y-m-d') ?? '-' }}</span>
                            <span>{{ Str::limit(strip_tags($article->content ?? ''), 60) }}</span>
                        </div>
                    </div>
                </label>
                @endforeach
            </div>
            <div class="mt-2 text-xs text-ai-dim">
                已选 <span id="articleCount" class="font-bold text-indigo-400">0</span> 篇文章
            </div>
            @endif
        </div>

        {{-- Step 2: 选择平台 --}}
        <div class="bento-card p-5">
            <h2 class="text-sm font-semibold text-ai-primary mb-1">📡 第二步：选择目标平台 <span class="text-red-400">*</span></h2>
            <p class="text-xs text-ai-dim mb-4">展开分类选择要分发到的平台（可多选），已开通的平台显示 ✓ 标识</p>

            <div class="space-y-3" id="platformTree">
                @foreach ($platformTree as $level1)
                <div class="border rounded-lg overflow-hidden">
                    {{-- 一级：发布方式 --}}
                    <button type="button"
                            class="w-full flex items-center justify-between px-4 py-3 bg-transparent hover:bg-indigo-50/10 text-sm font-medium text-ai-primary"
                            onclick="this.parentElement.classList.toggle('expanded')">
                        <span>{{ $level1['label'] }}</span>
                        <svg class="w-4 h-4 transition-transform tree-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    {{-- 二级：平台类别 --}}
                    <div class="hidden px-4 py-3 space-y-3 tree-children">
                        @foreach ($level1['children'] as $level2)
                        @if (!empty($level2['children']))
                        <div>
                            <div class="flex items-center gap-2 mb-2">
                                <input type="checkbox"
                                       class="level2-check rounded border-gray-600 text-indigo-400 focus:ring-indigo-500"
                                       data-group="{{ $level1['value'] }}-{{ $level2['value'] }}"
                                       onchange="toggleGroup(this)">
                                <span class="text-sm font-medium text-ai-primary">{{ $level2['label'] }}</span>
                                <span class="text-xs text-ai-dim">({{ count($level2['children']) }})</span>
                            </div>
                            {{-- 三级：具体平台 --}}
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-1.5 ml-6">
                                @foreach ($level2['children'] as $platform)
                                <label class="flex items-center gap-1.5 px-2 py-1.5 rounded hover:bg-indigo-50/5 cursor-pointer text-xs">
                                    <input type="checkbox" name="platform_keys[]" value="{{ $platform['value'] }}"
                                           class="platform-check rounded border-gray-600 text-indigo-400 focus:ring-indigo-500"
                                           data-group="{{ $level1['value'] }}-{{ $level2['value'] }}">
                                    <span class="text-ai-primary truncate">{{ $platform['label'] }}</span>
                                    @if ($platform['connected'] ?? false)
                                    <span class="text-emerald-500 text-xs ml-auto shrink-0">✓</span>
                                    @endif
                                    @if ($platform['supports_rpa'] ?? false)
                                    <span class="text-xs text-indigo-400 shrink-0" title="支持自动化">🤖</span>
                                    @endif
                                </label>
                                @endforeach
                            </div>
                        </div>
                        @endif
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
            <div class="mt-2 text-xs text-ai-dim">
                已选 <span id="platformCount" class="font-bold text-indigo-400">0</span> 个平台
            </div>
        </div>

        {{-- Step 3: 确认提交 --}}
        <div class="bento-card p-5">
            <h2 class="text-sm font-semibold text-ai-primary mb-1">🚀 第三步：确认发布</h2>
            <p class="text-xs text-ai-dim mb-4">提交后文章将进入分发队列，系统自动执行平台发布，GEO评分低于70分的文章自动优化</p>

            <div class="flex items-start gap-3 p-3 bg-amber-500/10 border border-amber-200 rounded-lg text-sm">
                <span class="text-amber-500 text-lg shrink-0">💡</span>
                <div class="text-amber-400">
                    <p class="font-medium">发布说明</p>
                    <ul class="list-disc list-inside text-xs space-y-0.5 mt-1">
                        <li>提交后文章自动进行 GEO 评分，低于 70 分的文章系统将自动追加 FAQ 和专家引用进行增强</li>
                        <li>分发按平台顺序错峰执行，避免瞬间并发触发平台风控</li>
                        <li>分发结果实时更新，可在任务详情页查看每篇文章在每个平台的发布状态</li>
                        <li>部分平台需要客户先完成授权，未授权平台将跳过发布</li>
                    </ul>
                </div>
            </div>

            <button type="submit" id="submitBtn"
                    class="mt-4 w-full rounded-lg bg-indigo-600 px-4 py-3 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    disabled>
                🚀 一键发布到所选平台
            </button>
            <p class="text-xs text-ai-dim text-center mt-2">请先选择至少1篇文章和1个平台</p>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
// 级联树展开/收起
document.querySelectorAll('.tree-children').forEach(el => {
    el.classList.remove('hidden'); // 默认展开
});
document.querySelectorAll('#platformTree .expanded .tree-arrow').forEach(el => {
    el.style.transform = 'rotate(180deg)';
});

// 二级全选/取消
function toggleGroup(checkbox) {
    const group = checkbox.dataset.group;
    document.querySelectorAll(`.platform-check[data-group="${group}"]`).forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateCounts();
}

// 计数更新
function updateCounts() {
    const articleCount = document.querySelectorAll('.article-check:checked').length;
    const platformCount = document.querySelectorAll('.platform-check:checked').length;
    document.getElementById('articleCount').textContent = articleCount;
    document.getElementById('platformCount').textContent = platformCount;
    document.getElementById('submitBtn').disabled = articleCount === 0 || platformCount === 0;
}

document.querySelectorAll('.article-check, .platform-check').forEach(cb => {
    cb.addEventListener('change', updateCounts);
});

// 级联组联动：三级变化时更新二级checkbox状态
document.querySelectorAll('.platform-check').forEach(cb => {
    cb.addEventListener('change', function() {
        const group = this.dataset.group;
        const groupCbs = document.querySelectorAll(`.platform-check[data-group="${group}"]`);
        const allChecked = Array.from(groupCbs).every(c => c.checked);
        const level2 = document.querySelector(`.level2-check[data-group="${group}"]`);
        if (level2) level2.checked = allChecked;
        updateCounts();
    });
});

updateCounts();
</script>
@endpush
