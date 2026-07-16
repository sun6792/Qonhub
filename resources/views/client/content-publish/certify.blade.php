@extends('client.layout')

@php
    $profile = $workspace->enterpriseProfile;
    $anchorService = app(\App\Services\GeoFlow\EnterpriseAnchorService::class);
    $summary = $profile?->company_full_name
        ? $anchorService->certificationSummary($profile)
        : null;
    // Filter to only B2B type platforms
    $b2bPlatforms = collect(\App\Services\GeoFlow\EnterpriseAnchorService::anchorPlatforms());
    $certifiedPlatforms = $summary
        ? $summary['platforms']->where('status', 'certified')->pluck('platform_key')->all()
        : [];
@endphp

@section('content')
<div class="max-w-5xl mx-auto px-4 py-6 space-y-5">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-ai-primary">🏢 B2B 企业认证</h1>
            <p class="text-sm text-ai-secondary mt-1">一键将企业信息认证入驻到B2B平台，建立信息锚点</p>
        </div>
        @if ($profile && $profile->company_full_name)
        <span class="text-xs px-2 py-1 rounded-full {{ $profile->isVerified() ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-400' }}">
            {{ $profile->isVerified() ? '✅ 企业档案已核验' : '📝 企业档案待核验' }}
        </span>
        @endif
    </div>

    {{-- 四步注册进度 --}}
    @php
        $stepStatus = $profile ? $profile->getRegisterStepStatus() : ['step1'=>false,'step2'=>false,'step3'=>false,'step4'=>false,'completed'=>0,'total'=>4,'can_register'=>false];
    @endphp
    <div class="grid grid-cols-4 gap-3">
        @foreach ([
            ['key'=>'step1','label'=>'公司资料','desc'=>'营业执照信息'],
            ['key'=>'step2','label'=>'联系人','desc'=>'姓名/手机/邮箱'],
            ['key'=>'step3','label'=>'地区行业','desc'=>'省份/城市/行业'],
            ['key'=>'step4','label'=>'产品服务','desc'=>'主营产品/关键词'],
        ] as $i => $step)
        <div class="rounded-xl p-4 text-center border-2 {{ $stepStatus[$step['key']] ? 'border-emerald-400 bg-emerald-500/10' : 'border-indigo-400/10 bg-transparent' }}">
            <div class="text-2xl mb-1">{{ $stepStatus[$step['key']] ? '✅' : ($i + 1) }}</div>
            <div class="text-sm font-medium {{ $stepStatus[$step['key']] ? 'text-emerald-400' : 'text-ai-primary' }}">{{ $step['label'] }}</div>
            <div class="text-xs text-ai-dim mt-0.5">{{ $step['desc'] }}</div>
        </div>
        @endforeach
    </div>
    <div class="text-center text-sm {{ $stepStatus['can_register'] ? 'text-emerald-400' : 'text-amber-400' }}">
        @if ($stepStatus['can_register'])
        ✅ 企业资料已完成，支持B2B一键自动注册
        @else
        ⚠️ 已完成 {{ $stepStatus['completed'] }}/{{ $stepStatus['total'] }}，还差 {{ $stepStatus['total'] - $stepStatus['completed'] }} 步即可开启自动注册
        @endif
    </div>

    {{-- 企业档案 — 客户端可自主编辑 --}}
    <div class="bento-card p-5">
        <h2 class="text-sm font-semibold text-ai-primary mb-3">📋 企业档案（B2B注册必填）</h2>
        <form method="POST" action="{{ route('client.enterprise-profile.save') }}">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <div>
                    <label class="text-xs text-ai-dim">公司全称 <span class="text-red-400">*</span></label>
                    <input name="company_full_name" value="{{ $profile->company_full_name ?? '' }}" required
                           class="w-full rounded-lg px-3 py-2 text-sm text-white focus:outline-none mt-1"
                           style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.15)" placeholder="营业执照上的公司全称">
                </div>
                <div>
                    <label class="text-xs text-ai-dim">统一社会信用代码</label>
                    <input name="unified_social_credit_code" value="{{ $profile->unified_social_credit_code ?? '' }}"
                           class="w-full rounded-lg px-3 py-2 text-sm text-white focus:outline-none mt-1"
                           style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.15)" placeholder="18位信用代码">
                </div>
                <div>
                    <label class="text-xs text-ai-dim">法定代表人</label>
                    <input name="legal_person" value="{{ $profile->legal_person ?? '' }}"
                           class="w-full rounded-lg px-3 py-2 text-sm text-white focus:outline-none mt-1"
                           style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.15)" placeholder="法人姓名">
                </div>
                <div>
                    <label class="text-xs text-ai-dim">所属行业</label>
                    <input name="industry" value="{{ $profile->industry ?? '' }}"
                           class="w-full rounded-lg px-3 py-2 text-sm text-white focus:outline-none mt-1"
                           style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.15)" placeholder="如：泵阀制造">
                </div>
                <div>
                    <label class="text-xs text-ai-dim">公司地址</label>
                    <input name="company_address" value="{{ $profile->company_address ?? '' }}"
                           class="w-full rounded-lg px-3 py-2 text-sm text-white focus:outline-none mt-1"
                           style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.15)" placeholder="详细地址">
                </div>
                <div>
                    <label class="text-xs text-ai-dim">联系电话</label>
                    <input name="company_phone" value="{{ $profile->company_phone ?? '' }}"
                           class="w-full rounded-lg px-3 py-2 text-sm text-white focus:outline-none mt-1"
                           style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.15)" placeholder="企业电话">
                </div>
                <div>
                    <label class="text-xs text-ai-dim">经营范围</label>
                    <textarea name="business_scope" rows="2"
                           class="w-full rounded-lg px-3 py-2 text-sm text-white focus:outline-none mt-1"
                           style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.15)" placeholder="营业执照上的经营范围">{{ $profile->business_scope ?? '' }}</textarea>
                </div>
                <div>
                    <label class="text-xs text-ai-dim">主营产品/服务</label>
                    <input name="products_services" value="{{ is_array($profile->products_services ?? null) ? implode('、', $profile->products_services) : ($profile->products_services ?? '') }}"
                           class="w-full rounded-lg px-3 py-2 text-sm text-white focus:outline-none mt-1"
                           style="background:rgba(14,16,28,0.8); border:1px solid rgba(99,102,241,0.15)" placeholder="如：微型泵阀、精密阀门">
                </div>
            </div>
            <button type="submit" class="mt-4 rounded-lg px-4 py-2 text-sm font-medium text-white"
                    style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">💾 保存企业资料</button>
            @if ($profile && $profile->company_full_name)
            <span class="ml-3 text-xs {{ $profile->getRegisterStepStatus()['can_register'] ? 'text-emerald-400' : 'text-amber-400' }}">
                完成 {{ $profile->getRegisterStepStatus()['completed'] }}/4 步
            </span>
            @endif
        </form>
    </div>

    {{-- B2B 平台认证状态 --}}
    <div class="bento-card p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold text-ai-primary">📡 B2B 平台认证状态</h2>
            @if ($summary)
            <span class="text-xs font-medium {{ $summary['certified'] === $summary['total'] ? 'text-emerald-400' : 'text-blue-600' }}">
                已认证 {{ $summary['certified'] }}/{{ $summary['total'] }}
            </span>
            @endif
        </div>

        @if ($profile && $profile->company_full_name && $b2bPlatforms->isNotEmpty())
        <form method="POST" action="{{ route('client.content-publish.certify-store') }}" id="certify-form">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($b2bPlatforms->take(15) as $key => $p)
                @php $isCertified = in_array($key, $certifiedPlatforms); @endphp
                <div class="rounded-lg border p-3 {{ $isCertified ? 'border-emerald-200 bg-emerald-500/10' : 'border-indigo-400/10' }}">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-ai-primary">{{ $p['name'] }}</span>
                        <span class="text-xs {{ $isCertified ? 'text-emerald-400' : 'text-ai-dim' }}">
                            {{ $isCertified ? '✅ 已认证' : '⏳ 未认证' }}
                        </span>
                    </div>
                    <div class="text-[10px] text-ai-dim mb-2">{{ $p['description'] }}</div>
                    <div class="flex items-center gap-2">
                        @if ($isCertified)
                        <span class="text-[10px] text-emerald-400">店铺已开通</span>
                        @else
                        <label class="flex items-center gap-1 cursor-pointer">
                            <input type="checkbox" name="platform_keys[]" value="{{ $key }}" class="rounded border-gray-600 text-indigo-400 focus:ring-indigo-500">
                            <span class="text-[10px] text-ai-secondary">选择认证</span>
                        </label>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>

            <div class="mt-4 flex gap-3">
                <button type="button" onclick="selectAllB2B()" class="text-xs text-indigo-400 hover:underline">全选未认证</button>
                <button type="submit" class="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                        id="certify-submit-btn" disabled>
                    🚀 一键认证所选平台
                </button>
                <button type="button" onclick="oneClickAll()"
                        class="rounded-lg px-6 py-2.5 text-sm font-medium text-white"
                        style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
                    ⚡ 一键入驻全部
                </button>
            </div>
            <p class="text-xs text-ai-dim mt-2">
                💡 认证后，企业的工商信息将自动入驻以上B2B平台，被主流大模型收录引用。
                运营团队将在1-3个工作日内完成认证。
            </p>
        </form>
        @else
        <div class="py-8 text-center text-sm text-ai-dim">
            请先完善企业档案后再进行B2B平台认证
        </div>
        @endif
    </div>

    {{-- 认证历史 --}}
    @php $certTasks = \App\Models\ContentPublishTask::query()
        ->where('workspace_id', (int)$workspace->id)
        ->where('type', 'certify')
        ->orderByDesc('created_at')
        ->limit(10)
        ->get();
    @endphp
    @if ($certTasks->isNotEmpty())
    <div class="bento-card overflow-hidden">
        <div class="border-b px-5 py-3"><h2 class="text-sm font-semibold text-ai-primary">📋 认证历史</h2></div>
        @foreach ($certTasks as $t)
        <a href="{{ route('client.content-publish.show', $t->id) }}" class="flex items-center justify-between px-5 py-3 hover:bg-indigo-50/5 border-b last:border-0">
            <div class="text-sm text-ai-primary">{{ $t->task_name }}</div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-ai-dim">{{ $t->completed_jobs }}/{{ $t->total_jobs }}</span>
                <span class="text-xs px-2 py-0.5 rounded-full {{ $t->status === 'completed' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-blue-500/10 text-blue-700' }}">
                    {{ $t->status === 'completed' ? '已完成' : '进行中' }}
                </span>
            </div>
        </a>
        @endforeach
    </div>
    @endif
</div>

<script>
document.querySelectorAll('input[name="platform_keys[]"]').forEach(cb => {
    cb.addEventListener('change', () => {
        const checked = document.querySelectorAll('input[name="platform_keys[]"]:checked').length;
        document.getElementById('certify-submit-btn').disabled = checked === 0;
    });
});
function selectAllB2B() {
    document.querySelectorAll('input[name="platform_keys[]"]:not(:checked)').forEach(cb => cb.click());
    document.getElementById('certify-submit-btn').disabled = false;
}
function oneClickAll() {
    // 全选所有未认证平台
    document.querySelectorAll('input[name="platform_keys[]"]:not(:checked)').forEach(cb => cb.checked = true);
    // 立即提交
    document.getElementById('certify-form').submit();
}
</script>
@endsection
