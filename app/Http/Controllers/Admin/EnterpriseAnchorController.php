<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentPublisherAccount;
use App\Models\EnterpriseAnchorCertification;
use App\Models\EnterpriseProfile;
use App\Models\Workspace;
use App\Services\GeoFlow\EnterpriseAnchorService;
use App\Services\GeoFlow\Publishing\AccountPoolService;
use App\Services\GeoFlow\Publishing\RpaEngineClient;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class EnterpriseAnchorController extends Controller
{
    public function __construct(
        private readonly EnterpriseAnchorService $anchorService,
    ) {}

    // ─── 信息锚点总览台 ───────────────────────────────

    public function overview(): View
    {
        $query = Workspace::query()
            ->where('status', 'active')
            ->with('enterpriseProfile.certifications')
            ->orderBy('name');
        $this->scopeByOperatorWorkspaces($query, Workspace::class);
        $workspaces = $query->get();

        $platforms = EnterpriseAnchorService::anchorPlatformsByPriority();
        $totalPlatforms = count($platforms);

        $stats = [
            'total_workspaces' => $workspaces->count(),
            'with_profile' => 0,
            'profiles_verified' => 0,
            'total_certified' => 0,
            'total_pending' => 0,
        ];

        $workspaceData = [];
        foreach ($workspaces as $ws) {
            $profile = $ws->enterpriseProfile;
            $hasProfile = $profile !== null && ! empty($profile->company_full_name);
            if ($hasProfile) {
                $stats['with_profile']++;
                if ($profile->isVerified()) {
                    $stats['profiles_verified']++;
                }
            }

            $summary = $profile
                ? $this->anchorService->certificationSummary($profile)
                : ['certified' => 0, 'pending' => $totalPlatforms, 'expired' => 0, 'total' => $totalPlatforms, 'platforms' => collect()];

            $stats['total_certified'] += $summary['certified'];
            $stats['total_pending'] += $summary['pending'];

            $workspaceData[] = [
                'workspace' => $ws,
                'has_profile' => $hasProfile,
                'profile' => $profile,
                'summary' => $summary,
            ];
        }

        return view('admin.enterprise-anchor.overview', [
            'pageTitle' => '信息锚点总览 - GEO 企业认证管理',
            'activeMenu' => 'enterprise-anchor',
            'adminSiteName' => AdminWeb::siteName(),
            'platforms' => $platforms,
            'totalPlatforms' => $totalPlatforms,
            'stats' => $stats,
            'workspaceData' => $workspaceData,
        ]);
    }

    // ─── 单个工作空间的企业锚点管理 ───────────────────

    public function manage(string $slug): View
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();
        $this->authorizeWorkspaceAccess((int) $workspace->id);
        $profile = $this->anchorService->getOrInitProfile($workspace);
        $summary = $this->anchorService->certificationSummary($profile);
        $napCheck = $profile->company_full_name ? $this->anchorService->napwConsistencyCheck($profile) : null;
        $coverage = $profile->exists ? $this->anchorService->llmCoverageReport($profile) : null;

        return view('admin.enterprise-anchor.manage', [
            'pageTitle' => $workspace->name.' - 企业信息锚点',
            'activeMenu' => 'workspaces',
            'adminSiteName' => AdminWeb::siteName(),
            'workspace' => $workspace,
            'profile' => $profile,
            'summary' => $summary,
            'napCheck' => $napCheck,
            'coverage' => $coverage,
            'platforms' => EnterpriseAnchorService::anchorPlatformsByPriority(),
        ]);
    }

    // ─── 保存企业档案 ─────────────────────────────────

    public function saveProfile(Request $request, string $slug): RedirectResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();
        $this->authorizeWorkspaceAccess((int) $workspace->id);

        $data = $request->validate([
            'company_full_name' => ['required', 'string', 'max:200'],
            'company_short_name' => ['nullable', 'string', 'max:100'],
            'unified_social_credit_code' => ['nullable', 'string', 'max:50'],
            'legal_person' => ['nullable', 'string', 'max:50'],
            'registered_capital' => ['nullable', 'string', 'max:50'],
            'establishment_date' => ['nullable', 'date'],
            'business_scope' => ['nullable', 'string'],
            'company_province' => ['nullable', 'string', 'max:20'],
            'company_city' => ['nullable', 'string', 'max:20'],
            'company_address' => ['nullable', 'string', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:30'],
            'company_email' => ['nullable', 'email', 'max:100'],
            'company_website' => ['nullable', 'url', 'max:200'],
            'registration_phone' => ['nullable', 'string', 'max:30'],
            'registration_authorized' => ['nullable', 'boolean'],
            'industry' => ['nullable', 'string', 'max:50'],
            'products_services' => ['nullable', 'string'],
        ]);

        $this->anchorService->saveProfile($workspace, $data);

        return redirect()
            ->route('admin.enterprise-anchor.manage', $slug)
            ->with('success', '企业档案已保存');
    }

    // ─── 平台认证操作 ─────────────────────────────────

    public function markCertified(Request $request, string $slug): RedirectResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();
        $this->authorizeWorkspaceAccess((int) $workspace->id);
        $profile = $this->anchorService->getOrInitProfile($workspace);

        $payload = $request->validate([
            'platform_key' => ['required', 'string'],
            'platform_account_id' => ['nullable', 'string', 'max:100'],
            'platform_page_url' => ['nullable', 'url', 'max:300'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $platforms = EnterpriseAnchorService::allAnchorPlatforms();
        $platformInfo = $platforms[$payload['platform_key']] ?? null;
        if (! $platformInfo) {
            return back()->withErrors('未知平台');
        }

        $adminId = (int) ($request->user('admin')?->id ?? 0);

        $this->anchorService->markCertified(
            profile: $profile,
            platformKey: $payload['platform_key'],
            adminId: $adminId,
            platformAccountId: $payload['platform_account_id'] ?? '',
            platformPageUrl: $payload['platform_page_url'] ?? '',
            notes: $payload['notes'] ?? null,
            expiresInMonths: $platformInfo['expires_in_months'],
        );

        return redirect()
            ->route('admin.enterprise-anchor.manage', $slug)
            ->with('success', $platformInfo['name'].' 已标记为认证完成');
    }

    public function revokeCertification(Request $request, string $slug): RedirectResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();
        $this->authorizeWorkspaceAccess((int) $workspace->id);
        $profile = $this->anchorService->getOrInitProfile($workspace);

        $payload = $request->validate([
            'platform_key' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $this->anchorService->revokeCertification(
            profile: $profile,
            platformKey: $payload['platform_key'],
            reason: $payload['reason'] ?? '',
        );

        return redirect()
            ->route('admin.enterprise-anchor.manage', $slug)
            ->with('success', '已取消认证');
    }

    /**
     * RPA 自动注册 — 企业档案 → RPA 引擎 → 注册认证 → 自动标记已认证。
     */
    public function rpaRegister(Request $request, string $slug, string $platformKey): RedirectResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();
        $this->authorizeWorkspaceAccess((int) $workspace->id);
        $profile = EnterpriseProfile::query()->where('workspace_id', (int) $workspace->id)->first();

        if (! $profile || empty($profile->company_full_name)) {
            return back()->withErrors('请先完善企业档案再使用自动注册');
        }

        $platforms = EnterpriseAnchorService::allAnchorPlatforms();
        $platformInfo = $platforms[$platformKey] ?? null;
        if (! $platformInfo || empty($platformInfo['supports_rpa'])) {
            return back()->withErrors('该平台暂不支持RPA自动注册');
        }

        // 获取或创建平台账号
        $account = ContentPublisherAccount::query()
            ->where('workspace_id', (int) $workspace->id)
            ->where('platform_key', $platformKey)
            ->first();

        if (! $account) {
            // 自动创建一个账号记录，密码用随机字符串（平台注册时创建）
            $account = ContentPublisherAccount::query()->create([
                'workspace_id' => (int) $workspace->id,
                'platform_key' => $platformKey,
                'platform_type' => 'b2b',
                'platform_name' => $platformInfo['name'],
                'account_name' => $workspace->name . ' 认证账号',
                'credential_type' => 'password',
                'credential_ciphertext' => app(\App\Support\GeoFlow\ApiKeyCrypto::class)->encrypt('RpaAuto' . time()),
                'status' => 'active',
                'health_status' => 'healthy',
                'requires_rpa' => true,
                'publish_interval_seconds' => 120,
                'daily_publish_limit' => 20,
            ]);
        }

        $enterpriseData = [
            'company_name' => $profile->company_full_name,
            'credit_code' => $profile->unified_social_credit_code,
            'legal_person' => $profile->legal_person,
            'business_scope' => $profile->business_scope,
            'province' => $profile->company_province,
            'city' => $profile->company_city,
            'address' => $profile->company_address,
            'phone' => $profile->registration_phone ?: $profile->company_phone,
            'email' => $profile->company_email,
            'website' => $profile->company_website,
            'industry' => $profile->industry,
            'products' => is_array($profile->products_services) ? implode('、', $profile->products_services) : '',
            'register_username' => 'qy_' . $profile->workspace_id . '_' . time(),
            'register_credential' => app(AccountPoolService::class)->decryptCredential($account),
        ];

        try {
            $rpaClient = app(RpaEngineClient::class);

            // 健康检查
            $health = $rpaClient->healthCheck();
            if (! ($health['healthy'] ?? false)) {
                Log::warning('RPA引擎未就绪', ['message' => $health['message'] ?? 'unknown']);

                return back()->withErrors('RPA 引擎未启动。请在项目目录执行: cd rpa-engine && node server.js');
            }

            $result = $rpaClient->executeTask([
                'platform' => $platformKey,
                'platform_name' => $platformInfo['name'],
                'action' => 'register_and_certify',
                'account' => [
                    'username' => $enterpriseData['register_username'],
                    'credential' => $enterpriseData['register_credential'],
                ],
                'enterprise' => $enterpriseData,
                'options' => [
                    'timeout_seconds' => 180,
                ],
            ]);

            if ($result['success'] ?? false) {
                $shopUrl = $result['shop_url'] ?? '';
                $cert = EnterpriseAnchorCertification::query()->firstOrCreate(
                    ['enterprise_profile_id' => (int) $profile->id, 'anchor_platform_key' => $platformKey],
                    [
                        'certification_status' => 'certified',
                        'certified_at' => now(),
                        'platform_page_url' => $shopUrl,
                    ]
                );
                if (! $cert->wasRecentlyCreated) {
                    $cert->forceFill([
                        'certification_status' => 'certified',
                        'certified_at' => now(),
                        'platform_page_url' => $shopUrl ?: $cert->platform_page_url,
                    ])->save();
                }

                return redirect()
                    ->route('admin.enterprise-anchor.manage', $slug)
                    ->with('success', "🤖 {$platformInfo['name']} RPA自动注册成功！店铺URL: " . ($shopUrl ?: '已同步'));
            }

            Log::error('RPA认证失败', ['result' => $result]);

            return back()->withErrors('RPA 认证失败: ' . ($result['error'] ?? '未知错误'));
        } catch (\Throwable $e) {
            Log::error('RPA注册异常', ['message' => $e->getMessage()]);

            return back()->withErrors('RPA 注册异常: ' . $e->getMessage());
        }
    }

    // ─── NAP+W 一致性检查 ────────────────────────────

    public function checkNapw(string $slug): RedirectResponse
    {
        $workspace = Workspace::query()->where('slug', $slug)->firstOrFail();
        $this->authorizeWorkspaceAccess((int) $workspace->id);
        $profile = $this->anchorService->getOrInitProfile($workspace);

        $result = $this->anchorService->napwConsistencyCheck($profile);

        if ($result['ok']) {
            return redirect()
                ->route('admin.enterprise-anchor.manage', $slug)
                ->with('success', 'NAP+W 一致性校验通过！企业信息在各大平台保持一致，大模型引用不会产生歧义。');
        }

        return redirect()
            ->route('admin.enterprise-anchor.manage', $slug)
            ->with('warning', '以下信息不完整，请完善后再校验：'.implode('、', $result['missing_fields']));
    }
}
