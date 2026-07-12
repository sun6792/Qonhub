@extends('admin.layouts.app')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
    {{-- Flash 消息 --}}
    @if (session('success'))
    <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">{{ session('success') }}</div>
    @endif
    @if (session('warning'))
    <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-700">{{ session('warning') }}</div>
    @endif

    {{-- 面包屑 --}}
    <div class="flex items-center gap-2 text-sm text-gray-500">
        <a href="{{ route('admin.workspaces.show', $workspace->slug) }}" class="hover:text-indigo-600 transition-colors duration-200">{{ $workspace->name }}</a>
        <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        <a href="{{ route('admin.enterprise-anchor.overview') }}" class="hover:text-indigo-600 transition-colors duration-200">信息锚点总览</a>
        <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        <span class="text-gray-900 font-medium">企业认证管理</span>
    </div>

    <div>
        <h1 class="text-xl font-semibold text-gray-900">🏢 {{ $workspace->name }} - 企业信息锚点</h1>
        <p class="mt-1 text-sm text-gray-500">管理企业档案和B2B平台认证，提升品牌在AI大模型中的引用率</p>
    </div>

    {{-- 状态卡片 --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        @php
        $statusItems = [
            ['value' => $summary['certified'], 'label' => '已认证平台', 'color' => 'text-emerald-600', 'bg' => 'bg-emerald-50'],
            ['value' => $summary['pending'], 'label' => '待认证', 'color' => 'text-amber-600', 'bg' => 'bg-amber-50'],
            ['value' => $summary['expired'], 'label' => '已过期', 'color' => 'text-gray-600', 'bg' => 'bg-gray-50'],
            ['value' => $napCheck ? ($napCheck['ok'] ? '✅ 一致' : '⚠️ 不完整') : '—', 'label' => 'NAP+W 一致性', 'color' => $napCheck ? ($napCheck['ok'] ? 'text-emerald-600' : 'text-red-600') : 'text-gray-400', 'bg' => $napCheck ? ($napCheck['ok'] ? 'bg-emerald-50' : 'bg-red-50') : 'bg-gray-50'],
        ];
        @endphp
        @foreach ($statusItems as $item)
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-center shadow-sm">
            <div class="text-2xl font-bold {{ $item['color'] }}">{{ $item['value'] }}</div>
            <div class="text-xs text-gray-500 mt-0.5">{{ $item['label'] }}</div>
        </div>
        @endforeach
    </div>

    @if ($coverage && $coverage['certified_platforms'] > 0)
    <div class="rounded-xl border border-blue-200 bg-blue-50 px-5 py-3 text-sm text-blue-800">
        🤖 <strong>LLM 引用覆盖：</strong>
        @foreach ($coverage['cited_by_llms'] as $llm => $count)
            <span class="inline-flex items-center ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium">{{ $llm }} {{ $count }}平台</span>
        @endforeach
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- 左栏：企业档案 --}}
        <div class="lg:col-span-1 space-y-5">
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3.5 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800">📋 企业档案</h2>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                        {{ $profile->isVerified() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                        {{ $profile->isVerified() ? '已核验' : '待核验' }}
                    </span>
                </div>
                <form method="POST" action="{{ route('admin.enterprise-anchor.save-profile', $workspace->slug) }}" class="px-5 py-4 space-y-3 text-sm">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">公司全称 <span class="text-red-400">*</span></label>
                        <input name="company_full_name" value="{{ old('company_full_name', $profile->company_full_name) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="营业执照上的完整名称" required>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">公司简称</label>
                            <input name="company_short_name" value="{{ old('company_short_name', $profile->company_short_name) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="品牌名/简称">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">统一社会信用代码</label>
                            <input name="unified_social_credit_code" value="{{ old('unified_social_credit_code', $profile->unified_social_credit_code) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="18位">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">法定代表人</label>
                            <input name="legal_person" value="{{ old('legal_person', $profile->legal_person) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">成立日期</label>
                            <input name="establishment_date" type="date" value="{{ old('establishment_date', $profile->establishment_date?->toDateString()) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">经营范围</label>
                        <textarea name="business_scope" rows="2" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="与营业执照一致">{{ old('business_scope', $profile->business_scope) }}</textarea>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">省</label>
                            <input name="company_province" value="{{ old('company_province', $profile->company_province) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">市</label>
                            <input name="company_city" value="{{ old('company_city', $profile->company_city) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">行业</label>
                            <input name="industry" value="{{ old('industry', $profile->industry) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="如：运动器材">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">详细地址</label>
                        <input name="company_address" value="{{ old('company_address', $profile->company_address) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="与营业执照一致">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">企业电话</label>
                            <input name="company_phone" value="{{ old('company_phone', $profile->company_phone) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">企业邮箱</label>
                            <input name="company_email" type="email" value="{{ old('company_email', $profile->company_email) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">企业官网</label>
                        <input name="company_website" type="url" value="{{ old('company_website', $profile->company_website) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="https://">
                    </div>
                    <div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                        <p class="text-xs font-medium text-amber-800 mb-2">🔐 B2B注册专用信息（运营代注册用，不再反复找客户要验证码）</p>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">注册专用手机号</label>
                                <input name="registration_phone" value="{{ old('registration_phone', $profile->registration_phone) }}" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-amber-500 focus:ring-amber-500" placeholder="收验证码用的手机号">
                            </div>
                            <div class="flex items-end">
                                <label class="flex items-center gap-2 cursor-pointer bg-white rounded-lg border border-gray-300 px-3 py-2 w-full">
                                    <input type="hidden" name="registration_authorized" value="0">
                                    <input type="checkbox" name="registration_authorized" value="1" {{ old('registration_authorized', $profile->registration_authorized) ? 'checked' : '' }} class="rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                                    <span class="text-sm text-gray-700">客户已授权代注册</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">主营产品/服务</label>
                        <textarea name="products_services" rows="2" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="一行一个产品/服务">{{ old('products_services', is_array($profile->products_services) ? implode("\n", $profile->products_services) : ($profile->products_services ?? '')) }}</textarea>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 transition-colors duration-200">💾 保存企业档案</button>
                        <a href="{{ route('admin.enterprise-anchor.check-napw', $workspace->slug) }}"
                           onclick="event.preventDefault(); document.getElementById('napw-check-form').submit();"
                           class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors duration-200 whitespace-nowrap">
                            🔍 NAP+W 校验
                        </a>
                    </div>
                    <form id="napw-check-form" method="POST" action="{{ route('admin.enterprise-anchor.check-napw', $workspace->slug) }}" class="hidden">@csrf</form>

                    @if ($napCheck && !$napCheck['ok'])
                    <div class="rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-xs text-red-700">
                        ⚠️ NAP+W 信息不完整：{{ implode('、', $napCheck['missing_fields']) }} 缺失，会影响大模型引用准确性。
                    </div>
                    @endif
                </form>
            </div>
        </div>

        {{-- 右栏：B2B 平台认证 --}}
        <div class="lg:col-span-2 space-y-5">
            <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3.5 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-800">📡 B2B 信息锚点</h2>
                        <p class="text-xs text-gray-400 mt-0.5">企业入驻认证，建立AI大模型品牌引用覆盖</p>
                    </div>
                    <span class="text-xs text-gray-400">{{ $summary['certified'] }}/{{ $summary['total'] }}</span>
                </div>

                <div class="divide-y divide-gray-50">
                    @foreach ($summary['platforms'] as $p)
                    @php
                        $info = $p['platform_info'];
                        $cert = $p['certification'];
                        $status = $p['status'];
                    @endphp
                    <div class="px-5 py-4 hover:bg-gray-50/30 transition-colors duration-150">
                        <div class="flex items-start justify-between gap-4">
                            {{-- 平台信息 --}}
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="w-7 h-7 rounded-lg flex items-center justify-center text-white text-xs font-bold shrink-0" style="background-color: {{ $info['color'] }}">
                                        {{ mb_substr($info['name'], 0, 1) }}
                                    </span>
                                    <span class="text-sm font-medium text-gray-800">{{ $info['name'] }}</span>
                                    {{-- 权重标签 --}}
                                    <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium
                                        {{ $info['citation_weight'] === 'highest' ? 'bg-red-50 text-red-700' : ($info['citation_weight'] === 'high' ? 'bg-amber-50 text-amber-700' : ($info['citation_weight'] === 'medium' ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600')) }}">
                                        {{ $info['citation_weight'] === 'highest' ? '顶级权重' : ($info['citation_weight'] === 'high' ? '高权重' : ($info['citation_weight'] === 'medium' ? '中权重' : '广覆盖')) }}
                                    </span>
                                    {{-- 覆盖方式标签 --}}
                                    @php $coverage = $info['coverage'] ?? 'manual'; @endphp
                                    @if ($coverage === 'self')
                                    <span class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-xs font-medium bg-red-100 text-red-700">
                                        🚀 聚合分发 · {{ $info['aggregator_scope'] ?? '30+站点' }}
                                    </span>
                                    @elseif ($coverage === 'aggregator')
                                    <span class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-xs font-medium bg-green-100 text-green-700">
                                        ✅ 天助网已覆盖
                                    </span>
                                    @elseif ($coverage === 'rpa')
                                    <span class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-xs font-medium bg-purple-100 text-purple-700">
                                        🤖 RPA可注册
                                    </span>
                                    @else
                                    <span class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-500">
                                        📝 需手动
                                    </span>
                                    @endif
                                    @if ($status === 'certified')
                                    <span class="text-xs text-emerald-600 font-medium">✅ 已认证</span>
                                    @elseif ($status === 'expired')
                                    <span class="text-xs text-gray-500 font-medium">⏰ 已过期</span>
                                    @elseif ($status === 'rejected')
                                    <span class="text-xs text-red-500 font-medium">❌ 未通过</span>
                                    @else
                                    <span class="text-xs text-gray-400">⏳ 待认证</span>
                                    @endif
                                </div>
                                <div class="text-xs text-gray-500 ml-9">
                                    {{ $info['cert_required'] ?? '企业注册认证' }}
                                </div>
                                <div class="text-xs text-gray-400 ml-9 mt-0.5">
                                    🤖 {{ implode('、', $info['cited_by_llms']) }}
                                    @if (($info['expires_in_months'] ?? null))
                                    · 有效期 {{ $info['expires_in_months'] }} 个月
                                    @endif
                                </div>
                                {{-- 平台直达链接 --}}
                                <div class="ml-9 mt-1.5 flex items-center gap-2">
                                    <a href="{{ $info['url'] }}" target="_blank" rel="noopener noreferrer"
                                       class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 hover:underline">
                                        🔗 访问平台 →
                                    </a>
                                    @if ($status !== 'certified' && !empty($info['register_url']))
                                    <a href="{{ $info['register_url'] }}" target="_blank" rel="noopener noreferrer"
                                       class="inline-flex items-center gap-1 text-xs text-orange-600 hover:text-orange-800 hover:underline">
                                        📝 前往注册 →
                                    </a>
                                    @endif
                                    @if ($status !== 'certified' && !empty($info['supports_rpa']))
                                    <form method="POST" action="{{ route('admin.enterprise-anchor.rpa-register', [$workspace->slug, $info['key']]) }}" class="inline" onsubmit="return confirm('确认使用RPA自动注册 {{ $info['name'] }}？\\n\\n系统将自动：\\n1. 打开注册页面\\n2. 填写企业档案信息\\n3. 提交认证\\n\\n如遇验证码需手动输入。')">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 text-xs text-green-600 hover:text-green-800 hover:underline font-medium">
                                            🤖 自动注册
                                        </button>
                                    </form>
                                    @endif
                                </div>

                                {{-- 已认证的额外信息 --}}
                                @if ($cert && $status === 'certified')
                                <div class="ml-9 mt-2 space-y-1 text-xs">
                                    @if ($cert->platform_account_id)
                                    <div class="text-gray-500">账号ID: <code class="bg-gray-100 px-1 rounded">{{ $cert->platform_account_id }}</code></div>
                                    @endif
                                    @if ($cert->platform_page_url)
                                    <div><a href="{{ $cert->platform_page_url }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline">{{ $cert->platform_page_url }}</a></div>
                                    @endif
                                    @if ($cert->certified_at)
                                    <div class="text-gray-400">认证时间: {{ $cert->certified_at->format('Y-m-d H:i') }}
                                        @if ($cert->expires_at) · 到期: {{ $cert->expires_at->format('Y-m-d') }} @endif
                                    </div>
                                    @endif
                                    @if ($cert->verification_notes)
                                    <div class="text-gray-500">📝 {{ $cert->verification_notes }}</div>
                                    @endif
                                </div>
                                @endif
                            </div>

                            {{-- 操作按钮 --}}
                            <div class="shrink-0">
                                @if ($status === 'certified')
                                <form method="POST" action="{{ route('admin.enterprise-anchor.revoke-certification', $workspace->slug) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="platform_key" value="{{ $info['key'] }}">
                                    <button class="text-xs text-red-500 hover:text-red-700 font-medium">取消认证</button>
                                </form>
                                @else
                                <button type="button" onclick="openCertifyModal('{{ $info['key'] }}', '{{ $info['name'] }}', '认证')"
                                        class="text-xs bg-indigo-600 text-white px-3 py-1.5 rounded-lg hover:bg-indigo-700 font-medium whitespace-nowrap">
                                    标记已认证
                                </button>
                                @endif
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>

                {{-- 底部提示 --}}
                <div class="border-t border-gray-100 px-5 py-3 bg-gray-50/50">
                    <div class="text-xs text-gray-500 space-y-1">
                        <p>🚀 <strong>聚合分发站（天助网）：</strong>注册1个平台 → 自动分发至30+合作B2B站点，性价比最高的锚点入口，建议优先完成。</p>
                        <p>💡 <strong>B2B锚点：</strong>在企业平台注册认证 → 企业信息被大模型收录 → AI回答"XX公司怎么样"时引用你的企业页。</p>
                        <p>🎯 <strong>目标：</strong>多平台B2B覆盖后，品牌在主流 AI（文心一言/豆包/通义千问/Kimi/DeepSeek）中的引用率和可信度显著提升。</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- 认证弹窗 --}}
<div id="certify-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50" onclick="if(event.target===this)closeCertifyModal()">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full mx-4 p-6" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <h4 class="text-lg font-bold">标记<span id="certify-action-label">认证</span>完成 - <span id="certify-platform-name"></span></h4>
            <button onclick="closeCertifyModal()" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <form id="certify-form" method="POST" class="space-y-3">
            @csrf
            <input type="hidden" name="platform_key" id="certify-platform-key">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">平台账号 ID（选填）</label>
                <input name="platform_account_id" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="如注册手机号/用户名">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">企业页面 URL（选填）</label>
                <input name="platform_page_url" type="url" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="https://...">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">备注</label>
                <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="认证过程中的备注信息"></textarea>
            </div>
            <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 transition-colors duration-200">✅ 确认已认证</button>
        </form>
    </div>
</div>
<script>
function openCertifyModal(key, name, action) {
    document.getElementById('certify-platform-key').value = key;
    document.getElementById('certify-platform-name').textContent = name;
    document.getElementById('certify-action-label').textContent = action || '认证';
    document.getElementById('certify-form').action = '{{ route('admin.enterprise-anchor.mark-certified', $workspace->slug) }}';
    document.getElementById('certify-modal').classList.remove('hidden');
}
function closeCertifyModal() {
    document.getElementById('certify-modal').classList.add('hidden');
}
</script>
@endsection
