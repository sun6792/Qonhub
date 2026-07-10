@extends('admin.layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
    {{-- 面包屑 + 操作 --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="{{ route('admin.workspaces.index') }}" class="hover:text-indigo-600 transition-colors duration-200">工作空间</a>
            <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gray-900 font-medium">{{ $workspace->name }}</span>
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ml-1
                {{ $workspace->status === 'active' ? 'bg-emerald-50 text-emerald-700' : ($workspace->status === 'paused' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-600') }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $workspace->status === 'active' ? 'bg-emerald-500' : ($workspace->status === 'paused' ? 'bg-amber-500' : 'bg-gray-400') }}"></span>
                {{ $workspace->status === 'active' ? '活跃' : ($workspace->status === 'paused' ? '暂停' : '归档') }}
            </span>
        </div>
        <div class="flex gap-2 shrink-0">
            <a href="{{ route('admin.workspaces.edit', $workspace->slug) }}" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors duration-200">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                编辑
            </a>
            <button onclick="copyClientUrl()" class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 transition-colors duration-200">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                复制客户链接
            </button>
        </div>
    </div>

    {{-- 标题 --}}
    <div>
        <h1 class="text-xl font-semibold text-gray-900">{{ $workspace->name }}</h1>
        @if ($workspace->description)
        <p class="mt-1 text-sm text-gray-500">{{ $workspace->description }}</p>
        @endif
    </div>

    {{-- 5 项统计 --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        @php
        $clientCount = \App\Models\ClientUser::where('workspace_id', (int)$workspace->id)->count();
        $statsItems = [
            ['value' => $stats['articles'], 'label' => '文章', 'color' => 'text-indigo-600', 'bg' => 'bg-indigo-50'],
            ['value' => $stats['tasks'], 'label' => '任务', 'color' => 'text-emerald-600', 'bg' => 'bg-emerald-50'],
            ['value' => $stats['knowledge_bases'], 'label' => '知识库', 'color' => 'text-purple-600', 'bg' => 'bg-purple-50'],
            ['value' => $clientCount, 'label' => '客户账号', 'color' => 'text-amber-600', 'bg' => 'bg-amber-50'],
            ['value' => $workspace->last_activity_at?->diffForHumans(null, true) ?? '—', 'label' => '最近活动', 'color' => 'text-rose-600', 'bg' => 'bg-rose-50'],
        ];
        @endphp
        @foreach ($statsItems as $item)
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-center shadow-sm">
            <div class="text-2xl font-bold {{ $item['color'] }}">{{ $item['value'] }}</div>
            <div class="text-xs text-gray-500 mt-0.5">{{ $item['label'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- 主内容区 --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- 左栏 --}}
        <div class="lg:col-span-2 space-y-5">
            {{-- 文章列表 --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-gray-800">最近文章</h2>
                    <span class="text-xs text-gray-400">{{ $stats['articles'] }} 篇</span>
                </div>
                @if ($articles->isNotEmpty())
                <div class="divide-y divide-gray-50">
                    @foreach ($articles->take(6) as $article)
                    <a href="{{ route('admin.articles.edit', $article->id) }}" class="flex items-center justify-between px-5 py-3 hover:bg-gray-50/60 transition-colors duration-150 group/item">
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium text-gray-800 truncate group-hover/item:text-indigo-600 transition-colors duration-150">{{ $article->title }}</div>
                            <div class="flex items-center gap-2 mt-0.5 text-xs text-gray-400">
                                <span>{{ $article->published_at?->format('m-d H:i') ?? '草稿' }}</span>
                                @if ($article->keywords)
                                <span class="text-indigo-500 truncate">{{ $article->keywords }}</span>
                                @endif
                            </div>
                        </div>
                        <span class="shrink-0 ml-3 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                            {{ $article->status === 'published' ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-500' }}">
                            {{ $article->status }}
                        </span>
                    </a>
                    @endforeach
                </div>
                @else
                <div class="py-12 text-center text-sm text-gray-400">暂无文章</div>
                @endif
            </div>

            {{-- 任务列表 --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-gray-800">关联任务</h2>
                    <span class="text-xs text-gray-400">{{ $stats['tasks'] }} 个</span>
                </div>
                @if ($tasks->isNotEmpty())
                <div class="divide-y divide-gray-50">
                    @foreach ($tasks->take(6) as $task)
                    <div class="flex items-center justify-between px-5 py-3">
                        <div class="text-sm font-medium text-gray-800 truncate">{{ $task->name }}</div>
                        <span class="shrink-0 ml-3 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                            {{ $task->status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600' }}">
                            {{ $task->status }}
                        </span>
                    </div>
                    @endforeach
                </div>
                @else
                <div class="py-12 text-center text-sm text-gray-400">暂无任务</div>
                @endif
            </div>
        </div>

        {{-- 右栏 --}}
        <div class="space-y-5">
            {{-- 客户信息 --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-gray-800">客户信息</h2>
                </div>
                <div class="px-5 py-4 space-y-3 text-sm">
                    @if ($workspace->client_company_name)
                    <div class="flex gap-2"><span class="text-gray-400 w-12 shrink-0">企业</span><span class="font-medium text-gray-800">{{ $workspace->client_company_name }}</span></div>
                    @endif
                    @if ($workspace->client_contact_name)
                    <div class="flex gap-2"><span class="text-gray-400 w-12 shrink-0">联系人</span><span class="text-gray-700">{{ $workspace->client_contact_name }}</span></div>
                    @endif
                    @if ($workspace->client_email)
                    <div class="flex gap-2"><span class="text-gray-400 w-12 shrink-0">邮箱</span><span class="text-indigo-600">{{ $workspace->client_email }}</span></div>
                    @endif
                    @if ($workspace->client_phone)
                    <div class="flex gap-2"><span class="text-gray-400 w-12 shrink-0">电话</span><span class="text-gray-700">{{ $workspace->client_phone }}</span></div>
                    @endif
                    @if (!$workspace->client_company_name && !$workspace->client_contact_name)
                    <p class="text-gray-400 text-xs">未填写客户信息</p>
                    @endif
                </div>
            </div>

            {{-- 客户平台授权状态 --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3.5 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800">📋 客户平台授权</h2>
                    @php $activeCount = \App\Models\ClientPlatformAccount::where('workspace_id', (int)$workspace->id)->where('status','active')->count(); @endphp
                    <span class="text-xs {{ $activeCount > 0 ? 'text-green-600' : 'text-gray-400' }}">{{ $activeCount }}/6 已授权</span>
                </div>
                <div class="px-5 py-4 space-y-3">
                    @php $accts = \App\Models\ClientPlatformAccount::where('workspace_id', (int)$workspace->id)->get()->keyBy('platform_key'); @endphp
                    @foreach (\App\Models\ClientPlatformAccount::supportedPlatforms() as $key => $info)
                    @php $acc = $accts->get($key); $connected = $acc && $acc->isActive(); @endphp
                    <div class="flex items-center justify-between border rounded-lg p-2.5 {{ $connected ? 'border-green-200 bg-green-50/30' : 'border-gray-100' }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium">{{ $info['name'] }}</span>
                                @if ($connected)
                                <span class="text-xs text-green-600">✅ {{ $acc->platform_account_name ?? '已连接' }}</span>
                                @else
                                <span class="text-xs text-gray-400">⚠️ 待注册</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-400 mt-0.5">{{ $info['need_verify'] }}</div>
                            @if ($connected && $acc->last_verified_at)
                            <div class="text-xs text-gray-400">最后验证: {{ $acc->last_verified_at->format('m-d H:i') }}</div>
                            @endif
                        </div>
                        <div class="shrink-0 ml-2">
                            <form method="POST" action="{{ route('admin.workspaces.toggle-platform', $workspace->slug) }}">
                                @csrf
                                <input type="hidden" name="platform_key" value="{{ $key }}">
                                @if ($connected)
                                <input type="hidden" name="platform_account_name" value="">
                                <button class="text-xs text-red-500 hover:text-red-700 font-medium">取消授权</button>
                                @else
                                <div class="flex items-center gap-1">
                                    <input name="platform_account_name" class="w-24 rounded border px-1.5 py-0.5 text-xs" placeholder="账号名（可选）">
                                    <button class="text-xs bg-blue-600 text-white px-2 py-1 rounded hover:bg-blue-700 font-medium">标记已注册</button>
                                </div>
                                @endif
                            </form>
                        </div>
                    @endforeach
                    <div class="text-xs text-gray-400 pt-1 border-t">
                        💡 以上 6 个平台需客户本人注册认证。其余平台由运营团队统一管理。
                    </div>
                </div>
            </div>

            {{-- 品牌关键词 --}}
            @php $keywords = $workspace->brandKeywordList(); @endphp
            @if (!empty($keywords))
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3.5">
                    <h2 class="text-sm font-semibold text-gray-800">AI 追踪关键词</h2>
                </div>
                <div class="px-5 py-4 flex flex-wrap gap-1.5">
                    @foreach ($keywords as $kw)
                    <span class="inline-flex rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-600">{{ $kw }}</span>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- 客户登录账号 --}}
            @php $clients = \App\Models\ClientUser::where('workspace_id', (int)$workspace->id)->get(); @endphp
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3.5 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800">客户登录账号</h2>
                    <span class="text-xs text-gray-400">{{ $clients->count() }} 个</span>
                </div>
                <div class="px-5 py-4 space-y-3">
                    @foreach ($clients as $c)
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 p-3">
                        <div>
                            <div class="text-sm font-medium text-gray-800">{{ $c->name }}</div>
                            <div class="text-xs text-gray-500">账号 <code class="bg-white px-1 rounded text-xs">{{ $c->username }}</code></div>
                            <div class="text-xs text-gray-400 mt-0.5">{{ $c->last_login_at ? '最后登录 '.$c->last_login_at->diffForHumans() : '从未登录' }}</div>
                        </div>
                        <div class="flex gap-2">
                            @if(Auth::guard('admin')->user()?->isSuperAdmin())
                            <button type="button" onclick="revealPassword({{ $c->id }})" class="text-xs font-medium text-indigo-600 hover:text-indigo-800 transition-colors duration-150">查看密码</button>
                            @endif
                            <button type="button" onclick="document.getElementById('rp{{ $c->id }}').classList.toggle('hidden')" class="text-xs font-medium text-amber-600 hover:text-amber-800 transition-colors duration-150">重置</button>
                        </div>
                    </div>
                    <form id="rp{{ $c->id }}" class="hidden" method="POST" action="{{ route('admin.workspaces.client-user.reset-password', $workspace->slug) }}">
                        @csrf
                        <input type="hidden" name="client_user_id" value="{{ $c->id }}">
                        <div class="flex gap-2">
                            <input name="new_password" class="flex-1 rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="新密码" minlength="6" required>
                            <button class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-medium text-white hover:bg-amber-600 transition-colors duration-150">确定</button>
                        </div>
                    </form>
                    @endforeach

                    <div class="border-t border-gray-100 pt-3">
                        <form method="POST" action="{{ route('admin.workspaces.client-user.create', $workspace->slug) }}" class="space-y-2">
                            @csrf
                            <input name="client_name" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="姓名（如：陈总）" required>
                            <div class="grid grid-cols-2 gap-2">
                                <input name="client_username" class="rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="登录账号" required>
                                <input name="client_password" type="text" class="rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="密码" required minlength="6">
                            </div>
                            <button class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors duration-200">创建客户账号</button>
                        </form>
                        @if (session('client_created'))
                        <div class="mt-2 rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 text-xs text-emerald-700">
                            ✅ 账号 {{ session('client_created.email') }} / 密码 {{ session('client_created.password') }}
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function copyClientUrl() {
    navigator.clipboard.writeText('{{ route('client.login') }}').then(() => {
        alert('客户登录地址已复制：\n{{ route('client.login') }}\n\n请将此地址和账号密码一并发送给客户。');
    });
}
async function revealPassword(clientUserId) {
    try {
        const resp = await fetch('{{ route('admin.workspaces.client-user.reveal-password', ['slug' => $workspace->slug, 'clientUserId' => '__ID__']) }}'.replace('__ID__', clientUserId));
        const data = await resp.json();
        if (data.ok) {
            alert('账号: ' + data.username + '\n密码: ' + data.password);
        } else {
            alert(data.error || '无法查看');
        }
    } catch(e) {
        alert('请求失败');
    }
}
</script>
@endpush
