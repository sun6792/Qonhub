@extends('admin.layouts.app')

@php
    $templates = $templates ?? [];
    $templateStats = $templateStats ?? [];
    $articles = $articles ?? collect();
    $search = $search ?? '';
    $workspaceId = $workspaceId ?? 0;
    $workspaces = $workspaces ?? collect();
@endphp

@section('content')
    <div class="space-y-8 px-4 sm:px-0">
        {{-- 头部 --}}
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">📦 内容弹药库</h1>
                <p class="mt-1 text-sm text-gray-600">选文章 → 点平台模板 → AI 改写 → 一键复制 → 去平台粘贴发布</p>
            </div>
            <a href="{{ route('admin.distribution.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                返回分发管理
            </a>
        </div>

        {{-- 模板组统计卡片 --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($templates as $tpl)
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-template-key="{{ $tpl['key'] }}">
                    <div class="text-xs font-medium uppercase text-gray-500">{{ $tpl['name'] }}</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $templateStats[$tpl['key']] ?? 0 }}</div>
                    <div class="mt-1 text-xs text-gray-500">个平台</div>
                    <p class="mt-2 text-xs text-gray-400 line-clamp-2">{{ $tpl['style'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- 搜索 + 工作空间过滤 --}}
        <form method="GET" action="{{ route('admin.distribution.armory') }}" class="flex flex-wrap gap-3">
            <select name="workspace_id" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" onchange="this.form.submit()">
                <option value="0">全部客户</option>
                @foreach ($workspaces as $ws)
                <option value="{{ $ws->id }}" {{ $workspaceId === (int)$ws->id ? 'selected' : '' }}>{{ $ws->name }}</option>
                @endforeach
            </select>
            <input type="text" name="search" value="{{ $search }}" placeholder="搜索已发布文章标题或关键词..."
                   class="block flex-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
            <button type="submit" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">搜索</button>
            @if ($search !== '' || $workspaceId > 0)
                <a href="{{ route('admin.distribution.armory') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">清除</a>
            @endif
        </form>

        {{-- 定时发布计划 --}}
        @php $scheduledItems = $scheduledItems ?? collect(); @endphp
        <div class="rounded-xl border border-amber-200 bg-amber-50 shadow-sm overflow-hidden">
            <div class="border-b border-amber-200 px-5 py-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-amber-800">⏰ 定时发布计划</h2>
                <span class="text-xs text-amber-600">{{ $scheduledItems->count() }} 条</span>
            </div>
            @if ($scheduledItems->isNotEmpty())
            <div class="divide-y divide-amber-100 text-sm">
                @foreach ($scheduledItems as $item)
                <div class="flex items-center justify-between px-5 py-2">
                    <div>
                        <span class="font-medium text-gray-700">{{ $item->article?->title ? mb_substr($item->article->title, 0, 40) : '文章#'.$item->article_id }}</span>
                        <span class="text-xs text-gray-400 ml-2">→ {{ $item->platform }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-gray-500">{{ $item->scheduled_at->format('m-d H:i') }}</span>
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium
                            {{ $item->status === 'pending' ? 'bg-blue-100 text-blue-700' : '' }}
                            {{ $item->status === 'completed' ? 'bg-green-100 text-green-700' : '' }}
                            {{ $item->status === 'failed' ? 'bg-red-100 text-red-700' : '' }}">
                            {{ ['pending'=>'等待中','processing'=>'发布中','completed'=>'已完成','failed'=>'失败','cancelled'=>'已取消'][$item->status] ?? $item->status }}
                        </span>
                        @if($item->status === 'pending')
                        <button onclick="cancelSchedule({{ $item->id }})" class="text-xs text-red-400 hover:text-red-600">取消</button>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="px-5 py-4 text-center text-sm text-amber-600">
                暂无定时计划。勾选上方文章 → 选平台 → 选时间 → 点 ⏰定时发布 即可创建。
            </div>
            @endif
        </div>

        {{-- 文章列表 --}}
        @if ($articles->isEmpty())
            <div class="rounded-lg bg-white p-10 text-center shadow">
                <i data-lucide="file-text" class="mx-auto mb-3 h-10 w-10 text-gray-400"></i>
                <div class="text-sm font-medium text-gray-900">暂无已发布文章</div>
                <div class="mt-1 text-sm text-gray-500">先在 🤖智能体 中启动工作流生成文章，再到这里分发。</div>
            </div>
        @else
            {{-- 批量操作栏 --}}
            <div id="batchBar" class="sticky top-0 z-10 rounded-lg bg-indigo-50 border border-indigo-200 p-3 flex items-center gap-3 shadow">
                <label class="flex items-center gap-1 text-sm"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" class="rounded"> 全选</label>
                <span id="selectedCount" class="text-sm text-indigo-700 font-medium">已选 0 篇</span>
                <select id="batchPlatform" class="rounded border-gray-300 text-sm">
                    <option value="">选择发布平台...</option>
                    <option value="toutiao_publish">今日头条</option>
                    <option value="baijiahao_publish">百家号</option>
                    <option value="xiaohongshu_publish">小红书</option>
                    <option value="sohu_publish">搜狐号</option>
                </select>
                <select id="batchTemplate" class="rounded border-gray-300 text-sm">
                    <option value="">选择改写模板（可选）</option>
                    @foreach ($templates as $tpl)
                    <option value="{{ $tpl['key'] }}">{{ $tpl['name'] }}</option>
                    @endforeach
                </select>
                <input type="datetime-local" id="batchScheduleTime" class="rounded border-gray-300 text-sm" title="定时发布时间">
                <button onclick="batchPublish()" class="rounded bg-indigo-600 px-3 py-1.5 text-sm text-white font-medium hover:bg-indigo-700">🚀 立即发布</button>
                <button onclick="batchSchedulePublish()" class="rounded bg-amber-500 px-3 py-1.5 text-sm text-white font-medium hover:bg-amber-600">⏰ 定时发布</button>
                <span id="batchStatus" class="text-xs text-gray-500"></span>
            </div>

            <div class="space-y-4">
                @foreach ($articles as $article)
                    <div class="rounded-lg border border-gray-200 bg-white shadow transition hover:shadow-md" id="article-{{ (int) $article->id }}">
                        <div class="absolute -mt-1 -ml-1">
                            <input type="checkbox" class="article-checkbox rounded" value="{{ (int) $article->id }}" onchange="updateBatchBar()" style="width:18px;height:18px;cursor:pointer">
                        </div>
                        <div class="border-b border-gray-100 bg-gray-50/50 px-5 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <h3 class="text-base font-semibold text-gray-900">{{ $article->title }}</h3>
                                    <div class="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                        <span>{{ $article->task?->name ?? '-' }}</span>
                                        <span>·</span>
                                        <span>{{ $article->published_at?->format('Y-m-d H:i') }}</span>
                                        <span>·</span>
                                        <span>{{ mb_strlen(strip_tags((string) $article->content), 'UTF-8') }} 字</span>
                                        @if ($article->keywords)
                                            <span>·</span>
                                            <span class="text-blue-600">{{ $article->keywords }}</span>
                                        @endif
                                    </div>
                                </div>
                                <span class="inline-flex shrink-0 items-center rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-800">已发布</span>
                            </div>
                        </div>

                        {{-- 模板按钮行 --}}
                        <div class="px-5 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-medium text-gray-500 mr-1">AI 改写为：</span>
                                @foreach ($templates as $tpl)
                                    <button type="button"
                                            data-rewrite-btn
                                            data-article-id="{{ (int) $article->id }}"
                                            data-template-key="{{ $tpl['key'] }}"
                                            data-template-name="{{ $tpl['name'] }}"
                                            class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-blue-50 hover:border-blue-300 hover:text-blue-700"
                                            title="AI 改写为「{{ $tpl['name'] }}」">
                                        <i data-lucide="sparkles" class="h-3 w-3"></i>
                                        {{ $tpl['name'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        {{-- 改写结果预览区（默认隐藏） --}}
                        <div data-rewrite-panel class="hidden border-t border-gray-100 px-5 py-4">
                            <div class="mb-3 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-700" data-rewrite-label>改写中...</span>
                                    <span class="text-xs text-gray-400" data-rewrite-status></span>
                                </div>
                                {{-- GEO 评分对比卡片（geoskills 落地） --}}
                                <div data-geo-score class="hidden mb-3 grid grid-cols-3 gap-3">
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-center">
                                        <div class="text-xs text-gray-500 mb-1">改写前</div>
                                        <div class="text-2xl font-bold text-red-500" data-geo-before>--</div>
                                    </div>
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-center">
                                        <div class="text-xs text-gray-500 mb-1">改写后</div>
                                        <div class="text-2xl font-bold text-green-500" data-geo-after>--</div>
                                    </div>
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-center">
                                        <div class="text-xs text-gray-500 mb-1">评级提升</div>
                                        <div class="text-lg font-bold text-purple-600" data-geo-grade>--</div>
                                        <div class="text-xs mt-1" data-geo-improvement></div>
                                    </div>
                                    <div class="col-span-3 text-xs text-gray-500 bg-amber-50 rounded-lg p-2 border border-amber-100" data-geo-suggestions style="display:none"></div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" data-copy-btn
                                            class="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-green-50 hover:border-green-300 hover:text-green-700 transition"
                                            title="复制改写内容">
                                        <i data-lucide="copy" class="h-3.5 w-3.5"></i>
                                        一键复制
                                    </button>
                                    <button type="button" data-collapse-btn
                                            class="inline-flex items-center rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                                            title="收起">
                                        <i data-lucide="chevron-up" class="h-4 w-4"></i>
                                    </button>
                                </div>
                            </div>
                            <div data-rewrite-content
                                 class="max-h-[400px] overflow-y-auto rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm leading-relaxed text-gray-800 whitespace-pre-wrap">
                                <div class="flex items-center justify-center py-8 text-gray-400" data-rewrite-loading>
                                    <i data-lucide="loader-2" class="mr-2 h-5 w-5 animate-spin"></i>
                                    AI 正在改写中...
                                </div>
                            </div>
                            {{-- 平台链接 --}}
                            <div data-platform-list class="mt-3 flex flex-wrap gap-1.5"></div>
                            {{-- 分发渠道推送 --}}
                            <div data-channel-push class="mt-3 hidden border-t border-gray-200 pt-3">
                                <div class="text-xs font-medium text-gray-600 mb-2">📡 推送到分发渠道：</div>
                                <div data-channel-checkboxes class="flex flex-wrap gap-2 mb-2"></div>
                                <div class="flex items-center gap-2">
                                    <button type="button" data-push-btn
                                            class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 transition disabled:opacity-50 disabled:cursor-not-allowed">
                                        <i data-lucide="send" class="h-3 w-3"></i>
                                        一键推送
                                    </button>
                                    <span data-push-result class="text-xs text-gray-500"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- 分页 --}}
            <div class="mt-6">
                {{ $articles->links() }}
            </div>
        @endif
    </div>

    {{-- Toast --}}
    <div id="toast" class="fixed right-6 top-6 z-50 hidden rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800 shadow-lg transition"></div>
@endsection

@push('scripts')
<script>
(function () {
    const REWRITE_URL = '{{ route('admin.distribution.armory.rewrite') }}';
    const PUBLISH_URL = '{{ route('admin.distribution.armory.publish') }}';
    const CHANNELS_URL = '{{ route('admin.distribution.armory.channels') }}';
    const CSRF = '{{ csrf_token() }}';
    let cachedChannels = null;

    async function loadChannels() {
        if (cachedChannels) return cachedChannels;
        try {
            const resp = await fetch(CHANNELS_URL);
            const data = await resp.json();
            cachedChannels = data.ok ? (data.channels || []) : [];
            return cachedChannels;
        } catch { return []; }
    }

    function showToast(msg, type = 'success') {
        const el = document.getElementById('toast');
        if (!el) return;
        el.textContent = msg;
        el.className = 'fixed right-6 top-6 z-50 rounded-lg border px-4 py-3 text-sm font-medium shadow-lg transition';
        el.classList.add(type === 'error'
            ? 'border-red-200 bg-red-50 text-red-800'
            : 'border-green-200 bg-green-50 text-green-800');
        el.classList.remove('hidden');
        setTimeout(() => el.classList.add('hidden'), 2500);
    }

    async function copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            showToast('✅ 已复制到剪贴板！去对应平台粘贴发布即可');
        } catch {
            // Fallback for older browsers
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast('✅ 已复制到剪贴板！');
        }
    }

    function getPlatformLinks(platforms) {
        if (!platforms || !platforms.length) return '';
        return platforms.map(p =>
            `<a href="${p.login_url}" target="_blank" rel="noopener noreferrer"
                class="inline-flex items-center rounded-md border border-gray-200 bg-white px-2 py-1 text-xs text-gray-600 hover:bg-blue-50 hover:border-blue-300 transition">
                <i data-lucide="external-link" class="mr-1 h-3 w-3"></i>${p.name}
            </a>`
        ).join('');
    }

    document.addEventListener('click', async function (e) {
        // Rewrite button
        const rewriteBtn = e.target.closest('[data-rewrite-btn]');
        if (rewriteBtn) {
            const articleId = rewriteBtn.dataset.articleId;
            const templateKey = rewriteBtn.dataset.templateKey;
            const templateName = rewriteBtn.dataset.templateName;
            const card = document.getElementById('article-' + articleId);
            if (!card) return;

            const panel = card.querySelector('[data-rewrite-panel]');
            const content = panel.querySelector('[data-rewrite-content]');
            const loading = panel.querySelector('[data-rewrite-loading]');
            const label = panel.querySelector('[data-rewrite-label]');
            const status = panel.querySelector('[data-rewrite-status]');
            const copyBtn = panel.querySelector('[data-copy-btn]');
            const platformList = panel.querySelector('[data-platform-list]');
            const prevContent = panel.querySelector('.rewritten-text');

            // Show panel + loading
            panel.classList.remove('hidden');
            if (prevContent) prevContent.remove();
            loading.classList.remove('hidden');
            label.textContent = templateName;
            status.textContent = '改写中...';
            copyBtn.disabled = true;
            copyBtn.classList.add('opacity-50', 'cursor-not-allowed');
            platformList.innerHTML = '';
            content.scrollTop = 0;

            try {
                const resp = await fetch(REWRITE_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                    body: JSON.stringify({ article_id: parseInt(articleId), template_key: templateKey }),
                });
                const data = await resp.json();

                loading.classList.add('hidden');

                if (!data.ok) {
                    status.textContent = '失败';
                    label.className = 'inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-1 text-xs font-medium text-red-700';
                    content.innerHTML = '<div class="text-red-600"></div>';
                    content.querySelector('.text-red-600').textContent = data.error || '未知错误';
                    return;
                }

                status.textContent = '改写完成 · ' + (data.rewritten ? data.rewritten.length : 0) + ' 字';
                label.className = 'inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700';
                copyBtn.disabled = false;
                copyBtn.classList.remove('opacity-50', 'cursor-not-allowed');

                // GEO 评分对比卡片（geoskills 落地）[新增]
                if (data.geo_score) {
                    const geoCard = panel.querySelector('[data-geo-score]');
                    geoCard.classList.remove('hidden');
                    geoCard.querySelector('[data-geo-before]').textContent = data.geo_score.before;
                    geoCard.querySelector('[data-geo-after]').textContent = data.geo_score.after;
                    geoCard.querySelector('[data-geo-grade]').textContent = data.geo_score.grade;
                    const imp = data.geo_score.improvement;
                    const impEl = geoCard.querySelector('[data-geo-improvement]');
                    impEl.textContent = (imp >= 0 ? '+' : '') + imp;
                    impEl.className = 'text-xs font-bold mt-1 ' + (imp >= 0 ? 'text-green-600' : 'text-red-600');

                    const suggEl = geoCard.querySelector('[data-geo-suggestions]');
                    if (data.geo_score.suggestions && data.geo_score.suggestions.length > 0) {
                        suggEl.style.display = 'block';
                        suggEl.innerHTML = '<strong>💡 优化建议：</strong>';
                        const list = document.createElement('div');
                        data.geo_score.suggestions.forEach(s => {
                            const item = document.createElement('div');
                            item.textContent = '• ' + s;
                            list.appendChild(item);
                        });
                        suggEl.appendChild(list);
                    }
                }

                const wrapper = document.createElement('div');
                wrapper.className = 'rewritten-text';
                wrapper.textContent = data.rewritten;
                content.appendChild(wrapper);

                // Render platform links
                const tpl = @json($templates).find(t => t.key === templateKey);
                if (tpl && tpl.platforms) {
                    platformList.innerHTML = getPlatformLinks(tpl.platforms);
                }

                // Load and render distribution channel checkboxes
                const channelPush = panel.querySelector('[data-channel-push]');
                const channelCheckboxes = panel.querySelector('[data-channel-checkboxes]');
                const pushBtn = panel.querySelector('[data-push-btn]');
                const pushResult = panel.querySelector('[data-push-result]');
                const channels = await loadChannels();
                if (channels.length > 0 && channelPush && channelCheckboxes) {
                    channelPush.classList.remove('hidden');
                    channelCheckboxes.innerHTML = channels.map(c =>
                        `<label class="inline-flex items-center gap-1 rounded border border-gray-200 bg-white px-2 py-1 text-xs cursor-pointer hover:bg-gray-50">
                            <input type="checkbox" value="${c.id}" data-channel-checkbox class="rounded border-gray-300">
                            <span>${c.name}</span>
                            <span class="text-gray-400">(${c.type})</span>
                        </label>`
                    ).join('');

                    // Push button handler
                    pushBtn.onclick = async function() {
                        const checked = panel.querySelectorAll('[data-channel-checkbox]:checked');
                        const selectedIds = Array.from(checked).map(cb => parseInt(cb.value));
                        if (selectedIds.length === 0) {
                            showToast('请至少选择一个分发渠道', 'error');
                            return;
                        }
                        pushBtn.disabled = true;
                        pushResult.textContent = '推送中...';
                        try {
                            const resp = await fetch(PUBLISH_URL, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                                body: JSON.stringify({
                                    article_id: parseInt(articleId),
                                    template_key: templateKey,
                                    rewritten_title: data.title,
                                    rewritten_content: data.rewritten,
                                    channel_ids: selectedIds,
                                }),
                            });
                            const result = await resp.json();
                            pushResult.textContent = result.summary || '';
                            showToast(result.ok ? '推送完成' : '推送失败');
                        } catch (err) {
                            pushResult.textContent = '网络错误';
                            showToast('推送请求失败: ' + err.message, 'error');
                        } finally {
                            pushBtn.disabled = false;
                        }
                    };
                }

                if (typeof lucide !== 'undefined') lucide.createIcons();

            } catch (err) {
                loading.classList.add('hidden');
                status.textContent = '网络错误';
                label.className = 'inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-1 text-xs font-medium text-red-700';
                content.innerHTML = '<div class="text-red-600"></div>';
                content.querySelector('.text-red-600').textContent = '请求失败: ' + (err.message || '未知错误');
            }

            return;
        }

        // Collapse button
        const collapseBtn = e.target.closest('[data-collapse-btn]');
        if (collapseBtn) {
            const panel = collapseBtn.closest('[data-rewrite-panel]');
            if (panel) panel.classList.add('hidden');
            return;
        }

        // Copy button
        const copyBtn = e.target.closest('[data-copy-btn]');
        if (copyBtn && !copyBtn.disabled) {
            const panel = copyBtn.closest('[data-rewrite-panel]');
            const rewritten = panel.querySelector('.rewritten-text');
            if (rewritten) {
                await copyToClipboard(rewritten.textContent);
            }
            return;
        }
    });

    // ── 批量操作 ──
    window.toggleSelectAll = function(cb) {
        document.querySelectorAll('.article-checkbox').forEach(c => { c.checked = cb.checked; });
        updateBatchBar();
    };
    window.updateBatchBar = function() {
        const checked = document.querySelectorAll('.article-checkbox:checked');
        const bar = document.getElementById('batchBar');
        const count = document.getElementById('selectedCount');
        if (checked.length > 0) {
            count.textContent = '已选 ' + checked.length + ' 篇';
        } else {
            count.textContent = '勾选文章后批量操作';
        }
    };
    window.cancelSchedule = async function(id) {
        if (!confirm('确认取消此定时发布？')) return;
        try {
            const resp = await fetch(`/geo_admin/distribution/armory/schedule-cancel/${id}`, {
                method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF }
            });
            const data = await resp.json();
            if (data.ok) location.reload();
        } catch(e) { alert('取消失败: ' + e.message); }
    };

    window.batchSchedulePublish = async function() {
        const platform = document.getElementById('batchPlatform').value;
        if (!platform) { alert('请选择发布平台'); return; }
        const scheduledAt = document.getElementById('batchScheduleTime').value;
        if (!scheduledAt) { alert('请选择定时发布时间'); return; }
        const checked = document.querySelectorAll('.article-checkbox:checked');
        if (checked.length === 0) { alert('请先勾选文章'); return; }
        const wsId = document.querySelector('select[name="workspace_id"]')?.value || '0';
        if (wsId === '0') { alert('请先在上方选择一个客户'); return; }

        const ids = Array.from(checked).map(c => parseInt(c.value));
        if (!confirm('将 ' + ids.length + ' 篇文章定时发布于 ' + scheduledAt + ' 到 ' + platform + '，确认？')) return;

        try {
            const resp = await fetch('{{ route('admin.distribution.armory.schedule-publish') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ article_ids: ids, workspace_id: parseInt(wsId), platform: platform, scheduled_at: scheduledAt })
            });
            const data = await resp.json();
            if (data.ok) { alert(data.message); location.reload(); }
            else { alert('失败: ' + (data.error || '未知错误')); }
        } catch(e) { alert('请求失败: ' + e.message); }
    };

    window.batchPublish = async function() {
        const status = document.getElementById('batchStatus');
        const platform = document.getElementById('batchPlatform').value;
        if (!platform) { alert('请选择发布平台'); return; }
        const checked = document.querySelectorAll('.article-checkbox:checked');
        if (checked.length === 0) { alert('请先勾选文章'); return; }
        const wsId = document.querySelector('select[name="workspace_id"]')?.value || '0';
        if (wsId === '0') { alert('请先在上方选择一个客户'); return; }

        const ids = Array.from(checked).map(c => parseInt(c.value));
        if (!confirm('将为 ' + ids.length + ' 篇文章发布到 ' + platform + '，确认？')) return;

        let ok = 0;
        for (const articleId of ids) {
            try {
                status.textContent = '⏳ 提交任务...';
                const body = { article_id: articleId, workspace_id: parseInt(wsId), platform: platform };
                const pResp = await fetch('{{ route('admin.distribution.armory.publish-rpa') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                    body: JSON.stringify(body)
                });
                const pData = await pResp.json();
                if (!pData.ok) { status.textContent = '❌ ' + (pData.error || '提交失败'); continue; }

                // 轮询 RPA 任务状态
                const taskId = pData.task_id;
                status.textContent = '⏳ 发布中...';
                for (let poll = 0; poll < 30; poll++) {
                    await new Promise(r => setTimeout(r, 2000));
                    try {
                        const tResp = await fetch('http://127.0.0.1:9901/api/v1/tasks/' + taskId, {
                            headers: { 'X-API-Key': '{{ config('geoflow.rpa_engine_api_key') }}' }
                        });
                        const tData = await tResp.json();
                        if (tData.status === 'completed') {
                            if (tData.result?.success) ok++;
                            status.textContent = '✅ ' + ok + '/' + ids.length + (tData.result?.article_url ? ' ' + tData.result.article_url : '');
                            break;
                        }
                        if (tData.status === 'failed') {
                            status.textContent = '❌ 第' + (ok+1) + '篇失败: ' + (tData.error || '');
                            break;
                        }
                        status.textContent = '⏳ 发布中...(' + (poll+1) + '/' + ids.length + ' 篇)';
                    } catch {}
                }
            } catch(e) {
                status.textContent = '❌ 网络错误: ' + e.message;
            }
        }
        if (ok === ids.length) status.textContent = '✅ 全部成功！' + ok + '/' + ids.length;
        else status.textContent = '⚠️ 完成 ' + ok + '/' + ids.length;
    };
})();
</script>
@endpush
