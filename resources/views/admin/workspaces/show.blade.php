@extends('admin.layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
    {{-- 面包屑 + 操作 --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="{{ route('admin.workspaces.index') }}" class="hover:text-indigo-600">工作空间</a>
            <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gray-900 font-medium">{{ $workspace->name }}</span>
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ml-1
                {{ $workspace->status === 'active' ? 'bg-emerald-50 text-emerald-700' : ($workspace->status === 'paused' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-600') }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $workspace->status === 'active' ? 'bg-emerald-500' : ($workspace->status === 'paused' ? 'bg-amber-500' : 'bg-gray-400') }}"></span>
                {{ $workspace->status === 'active' ? '活跃' : ($workspace->status === 'paused' ? '暂停' : '归档') }}
            </span>
        </div>
        <div class="flex gap-2 shrink-0">
            <a href="{{ route('admin.workspaces.edit', $workspace->slug) }}" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                ✏️ 编辑
            </a>
            <a href="{{ route('admin.enterprise-anchor.manage', $workspace->slug) }}" class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700">
                🏢 信息锚点
            </a>
            <button onclick="copyClientUrl()" class="inline-flex items-center gap-1 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100">
                📋 复制客户链接
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

    {{-- 7 项统计 --}}
    @php
        $clientCount = \App\Models\ClientUser::where('workspace_id', (int)$workspace->id)->count();
        $enterpriseProfile = $workspace->enterpriseProfile;
        $certSummary = $enterpriseProfile?->company_full_name
            ? app(\App\Services\GeoFlow\EnterpriseAnchorService::class)->certificationSummary($enterpriseProfile)
            : null;
        $statsItems = [
            ['value' => $stats['articles'], 'label' => '文章', 'color' => 'text-indigo-600'],
            ['value' => $stats['tasks'], 'label' => '任务', 'color' => 'text-emerald-600'],
            ['value' => $stats['knowledge_bases'], 'label' => '知识库', 'color' => 'text-purple-600'],
            ['value' => $clientCount, 'label' => '客户账号', 'color' => 'text-amber-600'],
            ['value' => $certSummary ? $certSummary['certified'].'/'.$certSummary['total'] : '—', 'label' => 'B2B认证', 'color' => 'text-blue-600'],
            ['value' => $enterpriseProfile?->nap_consistency_checked ? '✅' : '—', 'label' => 'NAP+W', 'color' => 'text-rose-600'],
            ['value' => $workspace->last_activity_at?->diffForHumans(null, true) ?? '—', 'label' => '最近活动', 'color' => 'text-teal-600'],
        ];
    @endphp
    <div class="grid grid-cols-3 sm:grid-cols-7 gap-2">
        @foreach ($statsItems as $item)
        <div class="rounded-lg border border-gray-200 bg-white p-3 text-center shadow-sm">
            <div class="text-lg font-bold {{ $item['color'] }}">{{ $item['value'] }}</div>
            <div class="text-[11px] text-gray-400 mt-0.5">{{ $item['label'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- 主内容区：两栏 --}}
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-5">

        {{-- 左栏（3/5）：文章 + 任务 --}}
        <div class="lg:col-span-3 space-y-5">
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                    <h2 class="text-sm font-semibold text-gray-800">📝 最近文章</h2>
                    <span class="text-xs text-gray-400">{{ $stats['articles'] }} 篇</span>
                </div>
                @if ($articles->isNotEmpty())
                <div class="divide-y divide-gray-50">
                    @foreach ($articles->take(8) as $article)
                    <a href="{{ route('admin.articles.edit', $article->id) }}" class="flex items-center justify-between px-5 py-3 hover:bg-gray-50/60 transition-colors group/item">
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium text-gray-800 truncate group-hover/item:text-indigo-600">{{ $article->title }}</div>
                            <div class="flex items-center gap-2 mt-0.5 text-xs text-gray-400">
                                <span>{{ $article->published_at?->format('m-d H:i') ?? '草稿' }}</span>
                                @if ($article->keywords)
                                <span class="text-indigo-500 truncate">{{ $article->keywords }}</span>
                                @endif
                            </div>
                        </div>
                        <span class="shrink-0 ml-3 rounded-full px-2 py-0.5 text-xs font-medium
                            {{ $article->status === 'published' ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-500' }}">
                            {{ $article->status === 'published' ? '已发布' : $article->status }}
                        </span>
                    </a>
                    @endforeach
                </div>
                @else
                <div class="py-10 text-center text-sm text-gray-400">暂无文章</div>
                @endif
            </div>

            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                    <h2 class="text-sm font-semibold text-gray-800">⚙️ 关联任务</h2>
                    <span class="text-xs text-gray-400">{{ $stats['tasks'] }} 个</span>
                </div>
                @if ($tasks->isNotEmpty())
                <div class="divide-y divide-gray-50">
                    @foreach ($tasks->take(8) as $task)
                    <a href="{{ route('admin.tasks.edit', $task->id) }}" class="flex items-center justify-between px-5 py-3 hover:bg-gray-50/60">
                        <div class="text-sm font-medium text-gray-800 truncate">{{ $task->name }}</div>
                        <span class="shrink-0 ml-3 rounded-full px-2 py-0.5 text-xs font-medium
                            {{ $task->status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600' }}">
                            {{ $task->status === 'active' ? '运行中' : $task->status }}
                        </span>
                    </a>
                    @endforeach
                </div>
                @else
                <div class="py-10 text-center text-sm text-gray-400">暂无任务</div>
                @endif
            </div>
        </div>

        {{-- 右栏（2/5）：客户信息 + 锚点 + 平台 + 账号 --}}
        <div class="lg:col-span-2 space-y-5">

            {{-- ① 客户档案 + 信息锚点（合二为一） --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800">🏢 {{ $enterpriseProfile?->company_full_name ?: '客户档案' }}</h2>
                    <a href="{{ route('admin.enterprise-anchor.manage', $workspace->slug) }}" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">管理 →</a>
                </div>
                <div class="px-5 py-3 space-y-2.5 text-sm">
                    {{-- 企业基本信息 --}}
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                        @if ($enterpriseProfile?->company_full_name)
                        <div><span class="text-gray-400">公司</span> <span class="text-gray-800 font-medium">{{ $enterpriseProfile->company_full_name }}</span></div>
                        @elseif ($workspace->client_company_name)
                        <div><span class="text-gray-400">公司</span> <span class="text-gray-800 font-medium">{{ $workspace->client_company_name }}</span></div>
                        @endif
                        @if ($workspace->client_contact_name)
                        <div><span class="text-gray-400">联系人</span> <span class="text-gray-700">{{ $workspace->client_contact_name }}</span></div>
                        @endif
                        @if ($enterpriseProfile?->company_phone || $workspace->client_phone)
                        <div><span class="text-gray-400">电话</span> <span class="text-gray-700">{{ $enterpriseProfile?->company_phone ?: $workspace->client_phone }}</span></div>
                        @endif
                        @if ($enterpriseProfile?->company_email || $workspace->client_email)
                        <div><span class="text-gray-400">邮箱</span> <span class="text-indigo-600 truncate">{{ $enterpriseProfile?->company_email ?: $workspace->client_email }}</span></div>
                        @endif
                    </div>

                    {{-- B2B 锚点进度 --}}
                    @if ($certSummary)
                    <div class="pt-2 border-t border-gray-100">
                        <div class="flex justify-between text-xs mb-1">
                            <span class="text-gray-500">B2B信息锚点</span>
                            <span class="font-medium {{ $certSummary['certified'] === $certSummary['total'] ? 'text-emerald-600' : 'text-blue-600' }}">{{ $certSummary['certified'] }}/{{ $certSummary['total'] }}</span>
                        </div>
                        <div class="bg-gray-200 rounded-full h-1.5 mb-2">
                            <div class="h-1.5 rounded-full {{ $certSummary['certified'] === $certSummary['total'] ? 'bg-emerald-500' : ($certSummary['certified'] > 0 ? 'bg-blue-500' : 'bg-gray-300') }}"
                                 style="width: {{ $certSummary['total'] > 0 ? round($certSummary['certified'] / $certSummary['total'] * 100) : 0 }}%"></div>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($certSummary['platforms'] as $p)
                            <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium
                                {{ $p['status'] === 'certified' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-400' }}">
                                {{ $p['platform_info']['name'] }} {{ $p['status'] === 'certified' ? '✓' : '' }}
                            </span>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    {{-- NAP+W 状态 --}}
                    @if ($enterpriseProfile?->nap_consistency_checked)
                    <div class="text-xs text-emerald-600">✅ NAP+W一致性校验通过</div>
                    @endif

                    {{-- 品牌关键词（内嵌） --}}
                    @php $keywords = $workspace->brandKeywordList(); @endphp
                    @if (!empty($keywords))
                    <div class="pt-2 border-t border-gray-100">
                        <div class="text-xs text-gray-400 mb-1">🔍 AI追踪关键词</div>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($keywords as $kw)
                            <span class="rounded-md bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-600">{{ $kw }}</span>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
            </div>

            {{-- ② 客户平台授权（6个自媒体平台，客户自己登录） --}}
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800">📋 客户平台授权</h2>
                    @php $activeCount = \App\Models\ClientPlatformAccount::where('workspace_id', (int)$workspace->id)->where('status','active')->count(); @endphp
                    <span class="text-xs {{ $activeCount > 0 ? 'text-green-600' : 'text-gray-400' }}">{{ $activeCount }}/6</span>
                </div>
                <div class="px-5 py-3">
                    @php $accts = \App\Models\ClientPlatformAccount::where('workspace_id', (int)$workspace->id)->get()->keyBy('platform_key'); @endphp
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (\App\Models\ClientPlatformAccount::supportedPlatforms() as $key => $info)
                        @php $acc = $accts->get($key); $connected = $acc && $acc->isActive(); @endphp
                        <div class="rounded-lg border p-2 {{ $connected ? 'border-green-200 bg-green-50/30' : 'border-gray-150 bg-gray-50/30' }}">
                            <div class="flex items-center justify-between gap-1">
                                <span class="text-xs font-medium text-gray-700 truncate">{{ $info['name'] }}</span>
                                <span class="text-[10px] {{ $connected ? 'text-green-600' : 'text-gray-400' }}">{{ $connected ? '✅' : '⏳' }}</span>
                            </div>
                            @if ($connected && $acc->platform_account_name)
                            <div class="text-[10px] text-green-600 mt-0.5 truncate">{{ $acc->platform_account_name }}</div>
                            @endif
                            @if (!$connected)
                            <form method="POST" action="{{ route('admin.workspaces.toggle-platform', $workspace->slug) }}" class="mt-1.5 flex gap-1">
                                @csrf
                                <input type="hidden" name="platform_key" value="{{ $key }}">
                                <input name="platform_account_name" class="flex-1 w-16 rounded border border-gray-300 px-1.5 py-0.5 text-[10px]" placeholder="账号名">
                                <button class="shrink-0 text-[10px] bg-blue-600 text-white px-1.5 py-0.5 rounded hover:bg-blue-700">标记</button>
                            </form>
                            @else
                            <form method="POST" action="{{ route('admin.workspaces.toggle-platform', $workspace->slug) }}" class="mt-1">
                                @csrf
                                <input type="hidden" name="platform_key" value="{{ $key }}">
                                <input type="hidden" name="platform_account_name" value="">
                                <button class="text-[10px] text-red-500 hover:text-red-700">取消授权</button>
                            </form>
                            @endif
                        </div>
                        @endforeach
                    </div>
                    <div class="text-[10px] text-gray-400 mt-2 pt-2 border-t">
                        💡 以上平台需客户本人注册。B2B认证由运营操作（见上方"信息锚点"）。
                    </div>
                </div>
            </div>

            {{-- ③ 客户登录账号 --}}
            @php $clients = \App\Models\ClientUser::where('workspace_id', (int)$workspace->id)->get(); @endphp
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800">🔑 客户登录账号</h2>
                    <span class="text-xs text-gray-400">{{ $clients->count() }} 个</span>
                </div>
                <div class="px-5 py-3 space-y-2.5">
                    @foreach ($clients as $c)
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 p-2.5">
                        <div class="min-w-0 flex-1">
                            <div class="text-xs font-medium text-gray-800">{{ $c->name }}</div>
                            <div class="text-[10px] text-gray-500">账号 <code class="bg-white px-1 rounded">{{ $c->username }}</code></div>
                            <div class="text-[10px] text-gray-400">{{ $c->last_login_at ? '最后登录 '.$c->last_login_at->diffForHumans() : '从未登录' }}</div>
                        </div>
                        <div class="flex gap-1.5 shrink-0 ml-2">
                            @if(Auth::guard('admin')->user()?->isSuperAdmin())
                            <button type="button" onclick="revealPassword({{ $c->id }})" class="text-[10px] font-medium text-indigo-600 hover:text-indigo-800">查看密码</button>
                            @endif
                            <button type="button" onclick="document.getElementById('rp{{ $c->id }}').classList.toggle('hidden')" class="text-[10px] font-medium text-amber-600 hover:text-amber-800">重置</button>
                            <form method="POST" action="{{ route('admin.workspaces.client-user.delete', ['slug' => $workspace->slug, 'clientUserId' => $c->id]) }}" class="inline" onsubmit="return confirm('确认删除客户账号 {{ $c->username }}？此操作不可恢复。')">
                                @csrf
                                <button class="text-[10px] font-medium text-red-500 hover:text-red-700">删除</button>
                            </form>
                        </div>
                    </div>
                    <form id="rp{{ $c->id }}" class="hidden" method="POST" action="{{ route('admin.workspaces.client-user.reset-password', $workspace->slug) }}">
                        @csrf
                        <input type="hidden" name="client_user_id" value="{{ $c->id }}">
                        <div class="flex gap-2">
                            <input name="new_password" class="flex-1 rounded-lg border-gray-300 px-2 py-1.5 text-xs focus:border-indigo-500 focus:ring-indigo-500" placeholder="新密码" minlength="6" required>
                            <button class="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-600">确定</button>
                        </div>
                    </form>
                    @endforeach

                    <div class="border-t border-gray-100 pt-2.5">
                        <form method="POST" action="{{ route('admin.workspaces.client-user.create', $workspace->slug) }}" class="space-y-2">
                            @csrf
                            <div class="grid grid-cols-3 gap-1.5">
                                <input name="client_name" class="rounded-lg border-gray-300 px-2 py-1.5 text-xs focus:border-indigo-500 focus:ring-indigo-500" placeholder="姓名" required>
                                <input name="client_username" class="rounded-lg border-gray-300 px-2 py-1.5 text-xs focus:border-indigo-500 focus:ring-indigo-500" placeholder="登录账号" required>
                                <input name="client_password" type="text" class="rounded-lg border-gray-300 px-2 py-1.5 text-xs focus:border-indigo-500 focus:ring-indigo-500" placeholder="密码" required minlength="6">
                            </div>
                            <button class="w-full rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700">创建客户账号</button>
                        </form>
                        @if (session('client_created'))
                        <div class="mt-2 rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-1.5 text-[10px] text-emerald-700">
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
