<?php

namespace App\Services\GeoFlow;

use App\Models\EnterpriseAnchorCertification;
use App\Models\EnterpriseProfile;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * 企业 B2B 信息锚点服务。
 *
 * 核心职责：
 * 1. 管理 B2B 锚点平台的定义和元数据
 * 2. 企业档案的创建、更新、NAP+W 一致性校验
 * 3. B2B 平台认证进度的追踪和统计
 *
 * 信息锚点 ≠ 内容分发：这些平台用来让企业信息被大模型收录引用，
 * 而不是用来发布文章获取流量。
 */
class EnterpriseAnchorService
{
    // ─── B2B 锚点平台定义 ──────────────────────────────

    /**
     * 所有支持的 B2B 信息锚点平台。
     *
     * 每个平台说明其被哪些大模型优先引用，以及认证所需材料。
     */
    // [精简] B2B锚点仅保留10个质量平台（与客户端凭证中心一致）
    public static function anchorPlatforms(): array
    {
        return [
            'tz1288' => [
                'key' => 'tz1288', 'name' => '天助网（聚合分发）', 'type' => 'b2b_aggregator',
                'icon' => 'rocket', 'color' => '#D4380D',
                'url' => 'https://www.tz1288.com/', 'register_url' => 'https://www.tz1288.com/',
                'citation_weight' => 'highest', 'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言','豆包','百度AI搜索'],
                'description' => 'B2B联合体，注册1次分发30+站点，2000万+企业','supports_rpa' => true,
                'aggregator_scope' => '30+ B2B联合体站点',
            ],
            'b2b168' => [
                'key' => 'b2b168', 'name' => '八方资源网', 'type' => 'b2b_marketplace',
                'icon' => 'b2b', 'color' => '#1677FF', 'coverage' => 'rpa',
                'url' => 'https://www.b2b168.com/', 'register_url' => 'https://www.b2b168.com/',
                'citation_weight' => 'high', 'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言','豆包','百度AI搜索'],
                'description' => '300+行业，超百万注册企业，二线B2B主流站点', 'supports_rpa' => true,
            ],
            'cn5135' => [
                'key' => 'cn5135', 'name' => '无忧商务网', 'type' => 'b2b_directory',
                'icon' => 'directory', 'color' => '#389E0D',
                'url' => 'https://www.cn5135.com/', 'register_url' => 'https://www.cn5135.com/',
                'citation_weight' => 'medium', 'cert_required' => '企业信息免费发布',
                'cited_by_llms' => ['百度AI搜索','文心一言'],
                'description' => '2004年上线，免费B2B推广+企业黄页，搜索引擎收录稳定','supports_rpa' => true,
            ],
            'k2b2b' => [
                'key' => 'k2b2b', 'name' => 'K2商务网', 'type' => 'b2b_directory',
                'icon' => 'b2b', 'color' => '#0891B2',
                'url' => 'https://www.k2b2b.com/', 'register_url' => 'https://www.k2b2b.com/',
                'citation_weight' => 'medium', 'cert_required' => '企业免费注册商铺',
                'cited_by_llms' => ['百度AI搜索'],
                'description' => '免费B2B信息发布，覆盖工业/家居/电子全品类','supports_rpa' => true,
            ],
            'lswang' => [
                'key' => 'lswang', 'name' => '领商网', 'type' => 'b2b_directory',
                'icon' => 'b2b', 'color' => '#7C3AED',
                'url' => 'https://www.lswgmt.net/', 'register_url' => 'https://www.lswgmt.net/',
                'citation_weight' => 'medium', 'cert_required' => '企业免费注册商铺',
                'cited_by_llms' => ['百度AI搜索'],
                'description' => '综合免费B2B产品推广站，自然搜索收录获客','supports_rpa' => true,
            ],
            'wanjiabiz' => [
                'key' => 'wanjiabiz', 'name' => '万家商务网', 'type' => 'b2b_directory',
                'icon' => 'b2b', 'color' => '#DC2626',
                'url' => 'https://www.wanjiabiz.com/', 'register_url' => 'https://www.wanjiabiz.com/',
                'citation_weight' => 'medium', 'cert_required' => '企业免费开通店铺',
                'cited_by_llms' => ['百度AI搜索'],
                'description' => '综合商贸B2B，覆盖家居/建材/电子/化工','supports_rpa' => true,
            ],
            'jiuzhouziyuan' => [
                'key' => 'jiuzhouziyuan', 'name' => '九州资源网', 'type' => 'b2b_directory',
                'icon' => 'factory', 'color' => '#15803D',
                'url' => 'https://www.jiuzhouziyuan.com/', 'register_url' => 'https://www.jiuzhouziyuan.com/',
                'citation_weight' => 'medium', 'cert_required' => '企业免费发布供应信息',
                'cited_by_llms' => ['百度AI搜索','文心一言'],
                'description' => '工业属性B2B，环保设备/化工/建材/五金类供应商信息','supports_rpa' => true,
            ],
            'chaxun123' => [
                'key' => 'chaxun123', 'name' => '查询123', 'type' => 'b2b_directory',
                'icon' => 'search', 'color' => '#EA580C',
                'url' => 'https://www.chaxun123.com/', 'register_url' => 'https://www.chaxun123.com/',
                'citation_weight' => 'low', 'cert_required' => '企业信息收录查询',
                'cited_by_llms' => ['百度AI搜索'],
                'description' => 'B2B企业信息查询+商机导航工具','supports_rpa' => true,
            ],
            'b2b188' => [
                'key' => 'b2b188', 'name' => 'B2B88商机导航', 'type' => 'b2b_directory',
                'icon' => 'directory', 'color' => '#2563EB',
                'url' => 'https://www.b2b188.cn/', 'register_url' => 'https://www.b2b188.cn/',
                'citation_weight' => 'low', 'cert_required' => '企业免费开通商铺',
                'cited_by_llms' => ['百度AI搜索'],
                'description' => 'B2B导航聚合站，汇总全网B2B入口',
            ],
            'wjw' => [
                'key' => 'wjw', 'name' => '全球五金网', 'type' => 'b2b_marketplace',
                'icon' => 'factory', 'color' => '#D97706',
                'url' => 'https://www.wjw.cn/', 'register_url' => 'https://www.wjw.cn/',
                'citation_weight' => 'medium', 'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言','百度AI搜索'],
                'description' => '五金行业垂直B2B，五金机电/机械设备/电子元器件','supports_rpa' => true,
            ],
        ];
    }

    /**
     * 合并 B2B + 媒体所有锚点平台（媒体已移除，仅返回 B2B）。
     */
    public static function allAnchorPlatforms(): array
    {
        return self::anchorPlatforms();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function anchorPlatformsByPriority(): array
    {
        $platforms = self::allAnchorPlatforms();
        $weights = ['highest' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

        uasort($platforms, function (array $a, array $b) use ($weights): int {
            $wa = $weights[$a['citation_weight'] ?? 'low'] ?? 999;
            $wb = $weights[$b['citation_weight'] ?? 'low'] ?? 999;

            return $wa <=> $wb;
        });

        return $platforms;
    }

    // ─── 企业档案 ──────────────────────────────────────

    /**
     * 为工作空间获取或初始化企业档案。
     */
    public function getOrInitProfile(Workspace $workspace): EnterpriseProfile
    {
        $profile = $workspace->enterpriseProfile;

        if (! $profile) {
            $profile = EnterpriseProfile::query()->create([
                'workspace_id' => (int) $workspace->id,
                'company_full_name' => (string) ($workspace->client_company_name ?? ''),
                'company_phone' => (string) ($workspace->client_phone ?? ''),
                'company_email' => (string) ($workspace->client_email ?? ''),
                'verification_status' => 'pending',
            ]);
        }

        return $profile;
    }

    /**
     * 创建或更新企业档案。
     *
     * @param  array<string, mixed>  $data
     */
    public function saveProfile(Workspace $workspace, array $data): EnterpriseProfile
    {
        $profile = $this->getOrInitProfile($workspace);

        $fillable = [
            'company_full_name', 'company_short_name',
            'unified_social_credit_code', 'legal_person',
            'registered_capital', 'establishment_date',
            'business_scope',
            'company_province', 'company_city', 'company_address',
            'company_phone', 'company_email', 'company_website',
            'industry', 'products_services',
            'business_license_path', 'company_logo_path',
        ];

        $updateData = [];
        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                // null → '' 避免 NOT NULL 约束报错
                $updateData[$field] = $data[$field] ?? '';
            }
        }

        if ($updateData !== []) {
            $profile->forceFill($updateData)->save();
        }

        // 同步回工作空间的客户信息字段
        if (array_key_exists('company_full_name', $updateData)) {
            $workspace->forceFill(['client_company_name' => $updateData['company_full_name']])->save();
        }

        return $profile->fresh();
    }

    /**
     * 标记企业档案为已核验。
     */
    public function verifyProfile(EnterpriseProfile $profile, int $adminId): void
    {
        $profile->forceFill([
            'verification_status' => 'verified',
            'verified_by' => $adminId,
            'verified_at' => now(),
        ])->save();
    }

    /**
     * NAP+W 一致性快速检查。
     *
     * 检查 company_full_name / company_address / company_phone / company_website
     * 四个核心字段是否全部非空。后续可扩展为调用天眼查/企查查 API 做交叉验证。
     *
     * @return array{ok: bool, missing_fields: array<int, string>}
     */
    public function napwConsistencyCheck(EnterpriseProfile $profile): array
    {
        $required = [
            'company_full_name' => '公司全称',
            'company_address' => '公司地址',
            'company_phone' => '企业电话',
            'company_website' => '企业官网',
        ];

        $missing = [];
        foreach ($required as $field => $label) {
            if (empty(trim((string) ($profile->{$field} ?? '')))) {
                $missing[] = $label;
            }
        }

        $ok = $missing === [];
        if ($ok) {
            $profile->forceFill(['nap_consistency_checked' => true])->save();
        }

        return ['ok' => $ok, 'missing_fields' => $missing];
    }

    // ─── 认证管理 ──────────────────────────────────────

    /**
     * 获取企业在某平台的认证记录（不存在则自动创建 pending 记录）。
     */
    public function getOrInitCertification(EnterpriseProfile $profile, string $platformKey): EnterpriseAnchorCertification
    {
        $cert = EnterpriseAnchorCertification::query()
            ->where('enterprise_profile_id', (int) $profile->id)
            ->where('anchor_platform_key', $platformKey)
            ->first();

        if (! $cert) {
            $cert = EnterpriseAnchorCertification::query()->create([
                'enterprise_profile_id' => (int) $profile->id,
                'anchor_platform_key' => $platformKey,
                'certification_status' => 'pending',
            ]);
        }

        return $cert;
    }

    /**
     * 标记平台认证完成。
     */
    public function markCertified(
        EnterpriseProfile $profile,
        string $platformKey,
        int $adminId,
        string $platformAccountId = '',
        string $platformPageUrl = '',
        ?string $notes = null,
        ?int $expiresInMonths = null,
    ): EnterpriseAnchorCertification {
        $cert = $this->getOrInitCertification($profile, $platformKey);

        $expiresAt = null;
        if ($expiresInMonths !== null && $expiresInMonths > 0) {
            $expiresAt = now()->addMonths($expiresInMonths);
        }

        $cert->forceFill([
            'platform_account_id' => $platformAccountId,
            'platform_page_url' => $platformPageUrl,
            'certification_status' => 'certified',
            'certified_by' => $adminId,
            'certified_at' => now(),
            'expires_at' => $expiresAt,
            'verification_notes' => $notes,
        ])->save();

        return $cert;
    }

    /**
     * 取消认证或标记为过期。
     */
    public function revokeCertification(EnterpriseProfile $profile, string $platformKey, string $reason = ''): void
    {
        $cert = EnterpriseAnchorCertification::query()
            ->where('enterprise_profile_id', (int) $profile->id)
            ->where('anchor_platform_key', $platformKey)
            ->first();

        if ($cert) {
            $status = $cert->isExpired() ? 'expired' : 'pending';
            $cert->forceFill([
                'certification_status' => $status,
                'verification_notes' => $reason ?: '取消认证',
            ])->save();
        }
    }

    /**
     * 获取某企业所有平台的认证状态摘要。
     *
     * @return array{certified: int, pending: int, expired: int, total: int, platforms: Collection}
     */
    public function certificationSummary(EnterpriseProfile $profile): array
    {
        $allPlatforms = self::allAnchorPlatforms();
        $existing = $profile->certifications()->get()->keyBy('anchor_platform_key');

        $result = collect();
        $certified = 0;
        $pending = 0;
        $expired = 0;

        foreach ($allPlatforms as $key => $info) {
            $cert = $existing->get($key);
            if ($cert && $cert->isCertified()) {
                if ($cert->isExpired()) {
                    $expired++;
                    $status = 'expired';
                } else {
                    $certified++;
                    $status = 'certified';
                }
            } elseif ($cert && $cert->certification_status === 'rejected') {
                $status = 'rejected';
            } else {
                $pending++;
                $status = 'pending';
            }

            $result->push([
                'platform_key' => $key,
                'platform_info' => $info,
                'certification' => $cert,
                'status' => $status,
            ]);
        }

        return [
            'certified' => $certified,
            'pending' => $pending,
            'expired' => $expired,
            'total' => count($allPlatforms),
            'platforms' => $result,
        ];
    }

    /**
     * 获取所有已认证平台的 LLM 引用覆盖情况。
     *
     * @return array{total_platforms: int, certified_platforms: int, cited_by_llms: array<string, int>}
     */
    public function llmCoverageReport(EnterpriseProfile $profile): array
    {
        $certificationSummary = $this->certificationSummary($profile);
        $allPlatforms = self::allAnchorPlatforms();

        $llmCounts = [];
        foreach ($certificationSummary['platforms'] as $p) {
            if ($p['status'] === 'certified') {
                $llms = $allPlatforms[$p['platform_key']]['cited_by_llms'] ?? [];
                foreach ($llms as $llm) {
                    $llmCounts[$llm] = ($llmCounts[$llm] ?? 0) + 1;
                }
            }
        }

        return [
            'total_platforms' => $certificationSummary['total'],
            'certified_platforms' => $certificationSummary['certified'],
            'cited_by_llms' => $llmCounts,
        ];
    }

    // ─── RPA 自动注册（P1 新增） ────────────────────────

    /**
     * 启动单平台 RPA 自动注册。
     *
     * @return array{rpa_task_id:string, cert_id:int}
     * @throws \RuntimeException
     */
    public function startRpaRegister(int $workspaceId, string $platformKey): array
    {
        $profile = EnterpriseProfile::query()->where('workspace_id', $workspaceId)->first();
        if (! $profile) {
            throw new \RuntimeException('企业档案不存在');
        }

        $stepStatus = $profile->getRegisterStepStatus();
        if (! $stepStatus['can_register']) {
            throw new \RuntimeException('企业资料未完成，请先完善四步资料');
        }

        $platforms = self::anchorPlatforms();
        $platformInfo = $platforms[$platformKey] ?? null;
        if (! $platformInfo) {
            throw new \RuntimeException('未知平台');
        }
        if (empty($platformInfo['supports_rpa'])) {
            throw new \RuntimeException('该平台暂不支持自动注册，请手动操作');
        }

        // 调用 RPA 引擎注册
        $rpaUrl = rtrim((string) config('geoflow.rpa_engine_url'), '/') . '/api/v1/register';
        $apiKey = (string) config('geoflow.rpa_engine_api_key');

        $products = is_array($profile->products_services)
            ? implode('、', array_slice($profile->products_services, 0, 5))
            : (string) ($profile->products_services ?? '');

        $payload = [
            'platform' => $platformKey,
            'account' => [
                'username' => $profile->company_full_name,
                'credential' => null,
            ],
            'enterprise' => [
                'workspace_id' => $workspaceId,
                'company_name' => $profile->company_full_name,
                'credit_code' => $profile->unified_social_credit_code,
                'legal_person' => $profile->legal_person,
                'business_scope' => $profile->business_scope,
                'address' => $profile->company_address,
                'province' => $profile->company_province,
                'city' => $profile->company_city,
                'phone' => $profile->contact_phone ?: $profile->company_phone,
                'email' => $profile->company_email,
                'website' => $profile->company_website,
                'products' => $products,
            ],
            'options' => [
                'workspace_id' => $workspaceId,
                'timeout_seconds' => 180,
            ],
        ];

        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders([
                    'X-Api-Key' => $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($rpaUrl, $payload);

            if (! $response->successful()) {
                throw new \RuntimeException('RPA 引擎响应异常: HTTP ' . $response->status());
            }

            $result = $response->json();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \RuntimeException('RPA 引擎不可达，请确认 RPA 服务已启动（端口 ' . config('geoflow.rpa_engine_url') . '）');
        }

        // 更新认证记录为进行中
        $cert = $this->getOrInitCertification($profile, $platformKey);
        $cert->forceFill([
            'rpa_task_id' => $result['task_id'] ?? null,
            'certification_status' => 'in_progress',
            'verification_notes' => 'RPA 自动注册中...',
        ])->save();

        \Illuminate\Support\Facades\Log::info('RPA register started', [
            'workspace_id' => $workspaceId,
            'platform' => $platformKey,
            'rpa_task_id' => $result['task_id'] ?? null,
            'cert_id' => $cert->id,
        ]);

        return [
            'rpa_task_id' => $result['task_id'] ?? '',
            'cert_id' => $cert->id,
        ];
    }
}
