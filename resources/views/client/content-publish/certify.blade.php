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
            <h1 class="text-xl font-bold text-gray-900">🏢 B2B 企业认证</h1>
            <p class="text-sm text-gray-500 mt-1">一键将企业信息认证入驻到B2B平台，建立信息锚点</p>
        </div>
        @if ($profile && $profile->company_full_name)
        <span class="text-xs px-2 py-1 rounded-full {{ $profile->isVerified() ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
            {{ $profile->isVerified() ? '✅ 企业档案已核验' : '📝 企业档案待核验' }}
        </span>
        @endif
    </div>

    {{-- 企业档案概览 --}}
    <div class="bg-white rounded-xl shadow-sm p-5">
        <h2 class="text-sm font-semibold text-gray-800 mb-3">📋 企业档案</h2>
        @if ($profile && $profile->company_full_name)
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
            <div><span class="text-gray-400">公司</span><div class="font-medium text-gray-800">{{ $profile->company_full_name }}</div></div>
            <div><span class="text-gray-400">信用代码</span><div class="font-medium text-gray-800">{{ $profile->unified_social_credit_code ?: '未填写' }}</div></div>
            <div><span class="text-gray-400">法人</span><div class="font-medium text-gray-800">{{ $profile->legal_person ?: '未填写' }}</div></div>
            <div><span class="text-gray-400">行业</span><div class="font-medium text-gray-800">{{ $profile->industry ?: '未填写' }}</div></div>
        </div>
        <div class="mt-3 text-xs text-gray-400">
            NAP+W 一致性：
            <span class="{{ $profile->nap_consistency_checked ? 'text-emerald-600' : 'text-amber-600' }}">
                {{ $profile->nap_consistency_checked ? '✅ 已校验' : '⚠️ 未校验（影响大模型引用准确性）' }}
            </span>
        </div>
        @else
        <div class="text-center py-6">
            <p class="text-gray-400 mb-2">尚未创建企业档案</p>
            <p class="text-xs text-gray-400">请联系运营团队完善企业工商信息后，再进行B2B平台认证</p>
        </div>
        @endif
    </div>

    {{-- B2B 平台认证状态 --}}
    <div class="bg-white rounded-xl shadow-sm p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold text-gray-800">📡 B2B 平台认证状态</h2>
            @if ($summary)
            <span class="text-xs font-medium {{ $summary['certified'] === $summary['total'] ? 'text-emerald-600' : 'text-blue-600' }}">
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
                <div class="rounded-lg border p-3 {{ $isCertified ? 'border-emerald-200 bg-emerald-50/30' : 'border-gray-200' }}">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-gray-800">{{ $p['name'] }}</span>
                        <span class="text-xs {{ $isCertified ? 'text-emerald-600' : 'text-gray-400' }}">
                            {{ $isCertified ? '✅ 已认证' : '⏳ 未认证' }}
                        </span>
                    </div>
                    <div class="text-[10px] text-gray-400 mb-2">{{ $p['description'] }}</div>
                    <div class="flex items-center gap-2">
                        @if ($isCertified)
                        <span class="text-[10px] text-emerald-600">店铺已开通</span>
                        @else
                        <label class="flex items-center gap-1 cursor-pointer">
                            <input type="checkbox" name="platform_keys[]" value="{{ $key }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-[10px] text-gray-500">选择认证</span>
                        </label>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>

            <div class="mt-4 flex gap-3">
                <button type="button" onclick="selectAllB2B()" class="text-xs text-indigo-600 hover:underline">全选未认证</button>
                <button type="submit" class="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                        id="certify-submit-btn" disabled>
                    🚀 一键认证所选平台
                </button>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                💡 认证后，企业的工商信息将自动入驻以上B2B平台，被主流大模型收录引用。
                运营团队将在1-3个工作日内完成认证。
            </p>
        </form>
        @else
        <div class="py-8 text-center text-sm text-gray-400">
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
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="border-b px-5 py-3"><h2 class="text-sm font-semibold text-gray-800">📋 认证历史</h2></div>
        @foreach ($certTasks as $t)
        <a href="{{ route('client.content-publish.show', $t->id) }}" class="flex items-center justify-between px-5 py-3 hover:bg-gray-50 border-b last:border-0">
            <div class="text-sm text-gray-800">{{ $t->task_name }}</div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-gray-400">{{ $t->completed_jobs }}/{{ $t->total_jobs }}</span>
                <span class="text-xs px-2 py-0.5 rounded-full {{ $t->status === 'completed' ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700' }}">
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
}
</script>
@endsection
