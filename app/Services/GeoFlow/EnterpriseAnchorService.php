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
    public static function anchorPlatforms(): array
    {
        return [

            // ═══════ 一类：顶级权重 B2B（百度/阿里生态，LLM 核心数据源） ═══════

            'baidu_aicaigou' => [
                'key' => 'baidu_aicaigou',
                'name' => '百度爱采购',
                'icon' => 'search',
                'color' => '#2563EB',
                'type' => 'b2b_marketplace',
                'coverage' => 'manual',
                'url' => 'https://b2b.baidu.com/',
                'register_url' => 'https://b2b.baidu.com/supplier/register',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'highest',
                'description' => '百度自家B2B，搜索结果和AI回答权重最高',
            ],
            'alibaba_1688' => [
                'key' => 'alibaba_1688',
                'name' => '阿里1688',
                'icon' => 'shopping',
                'color' => '#FF6A00',
                'type' => 'b2b_marketplace',
                'coverage' => 'aggregator',
                'coverage' => 'aggregator',
                'url' => 'https://www.1688.com/',
                'register_url' => 'https://www.1688.com/',
                'cert_required' => '企业营业执照 + 对公账户验证',
                'cited_by_llms' => ['文心一言', '豆包', '通义千问', 'Kimi', 'DeepSeek'],
                'citation_weight' => 'highest',
                'description' => '国内最大B2B平台，超千万企业入驻，大模型训练核心数据源',
            ],

            // ═══════ 二类：高权重 — 综合B2B平台 ═══════

            'huicong' => [
                'key' => 'huicong',
                'name' => '慧聪网',
                'icon' => 'b2b',
                'color' => '#E60012',
                'type' => 'b2b_marketplace',
                'coverage' => 'aggregator',
                'coverage' => 'aggregator',
                'url' => 'https://www.hc360.com/',
                'register_url' => 'https://www.hc360.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '豆包'],
                'citation_weight' => 'high',
                'description' => '老牌B2B，超1500万注册企业，百度收录权重高',
            ],
            'made_in_china' => [
                'key' => 'made_in_china',
                'name' => '中国制造网',
                'icon' => 'globe',
                'color' => '#E7482E',
                'type' => 'b2b_marketplace',
                'coverage' => 'manual',
                'url' => 'https://www.made-in-china.com/',
                'register_url' => 'https://www.made-in-china.com/',
                'cert_required' => '企业营业执照 + 对公账户',
                'cited_by_llms' => ['文心一言', '豆包', '通义千问'],
                'citation_weight' => 'high',
                'description' => '面向全球11种语言，外企查询主要B2B源',
            ],
            'china_cn' => [
                'key' => 'china_cn',
                'name' => '中国供应商',
                'icon' => 'b2b',
                'color' => '#D43030',
                'type' => 'b2b_marketplace',
                'coverage' => 'aggregator',
                'url' => 'https://cn.china.cn/',
                'register_url' => 'https://cn.china.cn/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '豆包'],
                'citation_weight' => 'high',
                'description' => '主推免费入驻，百度企业信息收录广',
            ],
            'gongchang' => [
                'key' => 'gongchang',
                'name' => '世界工厂网',
                'icon' => 'factory',
                'color' => '#009944',
                'type' => 'b2b_marketplace',
                'coverage' => 'manual',
                'url' => 'https://www.gongchang.com/',
                'register_url' => 'https://www.gongchang.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '豆包'],
                'citation_weight' => 'medium',
                'description' => '专注工业品B2B，制造业企业必录平台',
            ],
            'globalsources' => [
                'key' => 'globalsources',
                'name' => '环球资源',
                'icon' => 'globe',
                'color' => '#EE3124',
                'type' => 'b2b_marketplace',
                'coverage' => 'manual',
                'url' => 'https://www.globalsources.com/',
                'register_url' => 'https://www.globalsources.com/',
                'cert_required' => '企业营业执照 + 出口资质',
                'cited_by_llms' => ['文心一言', '通义千问'],
                'citation_weight' => 'medium',
                'description' => '多渠道B2B媒体，外贸企业信息权威源',
            ],
            'dhgate' => [
                'key' => 'dhgate',
                'name' => '敦煌网',
                'icon' => 'shopping',
                'color' => '#DD4C39',
                'type' => 'b2b_marketplace',
                'coverage' => 'manual',
                'url' => 'https://www.dhgate.com/',
                'register_url' => 'https://seller.dhgate.com/',
                'cert_required' => '企业营业执照 + 对公账户',
                'cited_by_llms' => ['文心一言', '豆包'],
                'citation_weight' => 'medium',
                'description' => '小额外贸B2B，跨境电商企业信息源',
            ],
            'tradekey' => [
                'key' => 'tradekey',
                'name' => 'TradeKey',
                'icon' => 'globe',
                'color' => '#0072BC',
                'type' => 'b2b_marketplace',
                'coverage' => 'manual',
                'url' => 'https://www.tradekey.com/',
                'register_url' => 'https://www.tradekey.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['通义千问', 'Kimi'],
                'citation_weight' => 'medium',
                'description' => '全球B2B，覆盖240个国家，国际LLM数据源',
            ],

            // ═══════ 四类：中等权重 — 企业黄页/目录 ═══════

            'makepolo' => [
                'key' => 'makepolo',
                'name' => '马可波罗网',
                'icon' => 'directory',
                'color' => '#0068B7',
                'type' => 'b2b_directory',
                'coverage' => 'aggregator',
                'url' => 'https://www.makepolo.com/',
                'register_url' => 'https://www.makepolo.com/',
                'cert_required' => '企业信息免费收录',
                'cited_by_llms' => ['百度AI搜索', '文心一言'],
                'citation_weight' => 'medium',
                'description' => 'B2B采购搜索引擎，百度收录量大',
            ],
            'huangye88' => [
                'key' => 'huangye88',
                'name' => '黄页88',
                'icon' => 'directory',
                'color' => '#F5A623',
                'type' => 'b2b_directory',
                'coverage' => 'rpa',
                'url' => 'https://www.huangye88.com/',
                'register_url' => 'https://www.huangye88.com/',
                'cert_required' => '企业信息免费收录',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '百万级企业黄页，搜索引擎收录覆盖面广',
            ],
            'shunqi' => [
                'key' => 'shunqi',
                'name' => '顺企网',
                'icon' => 'directory',
                'color' => '#27AE60',
                'type' => 'b2b_directory',
                'coverage' => 'rpa',
                'url' => 'https://www.11467.com/',
                'register_url' => 'https://www.11467.com/',
                'cert_required' => '企业信息免费收录',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '国内大型企业黄页，百度快照收录稳定',
            ],
            'qiyegu' => [
                'key' => 'qiyegu',
                'name' => '企业谷',
                'icon' => 'directory',
                'color' => '#8E44AD',
                'type' => 'b2b_directory',
                'coverage' => 'manual',
                'url' => 'https://www.qiyegu.com/',
                'register_url' => 'https://www.qiyegu.com/',
                'cert_required' => '企业信息免费收录',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'low',
                'description' => '企业信息黄页，增加搜索覆盖面',
            ],

            // ═══════ 五类：垂直/地域B2B ═══════

            'kompass' => [
                'key' => 'kompass',
                'name' => '康帕斯(Kompass)',
                'icon' => 'globe',
                'color' => '#003399',
                'type' => 'b2b_directory',
                'coverage' => 'manual',
                'url' => 'https://cn.kompass.com/',
                'register_url' => 'https://cn.kompass.com/',
                'cert_required' => '企业信息免费收录',
                'cited_by_llms' => ['通义千问', 'Kimi'],
                'citation_weight' => 'low',
                'description' => '国际B2B企业目录，覆盖70国，多语言LLM数据源',
            ],
            'ec21' => [
                'key' => 'ec21',
                'name' => 'EC21',
                'icon' => 'globe',
                'color' => '#1A5276',
                'type' => 'b2b_marketplace',
                'coverage' => 'aggregator',
                'url' => 'https://www.ec21.com/',
                'register_url' => 'https://www.ec21.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['通义千问'],
                'citation_weight' => 'low',
                'description' => '韩国最大B2B，亚洲区域LLM数据源',
            ],

            // ═══════ 七类：用户验证可用 — 国内真实可注册 B2B ═══════

            'tz1288' => [
                'key' => 'tz1288',
                'name' => '天助网（聚合分发）',
                'icon' => 'rocket',
                'color' => '#D4380D',
                'type' => 'b2b_aggregator',
                'coverage' => 'self',
                'url' => 'https://www.tz1288.com/',
                'register_url' => 'https://www.tz1288.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '豆包', '百度AI搜索'],
                'citation_weight' => 'highest',
                'description' => 'B2B联合体平台，2000万+注册企业，注册1次自动分发至30+合作B2B站点，百度收录2367万条',
                'aggregator_scope' => '30+ B2B联合体站点',
                'supports_rpa' => false,
            ],
            'b2b168' => [
                'key' => 'b2b168',
                'name' => '八方资源网',
                'icon' => 'b2b',
                'color' => '#1677FF',
                'type' => 'b2b_marketplace',
                'coverage' => 'rpa',
                'url' => 'https://www.b2b168.com/',
                'register_url' => 'https://www.b2b168.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '豆包', '百度AI搜索'],
                'citation_weight' => 'high',
                'description' => '老牌B2B，300+行业2800+城市，累计服务超百万注册企业，二线B2B主流站点',
                'supports_rpa' => true,
            ],
            'cn5135' => [
                'key' => 'cn5135',
                'name' => '无忧商务网',
                'icon' => 'directory',
                'color' => '#389E0D',
                'type' => 'b2b_directory',
                'coverage' => 'aggregator',
                'url' => 'https://www.cn5135.com/',
                'register_url' => 'https://www.cn5135.com/',
                'cert_required' => '企业信息免费发布',
                'cited_by_llms' => ['百度AI搜索', '文心一言'],
                'citation_weight' => 'medium',
                'description' => '2004年上线，免费B2B推广+企业黄页，搜索引擎收录稳定，中小商家常用',
            ],
            'k2b2b' => [
                'key' => 'k2b2b',
                'name' => 'K2商务网',
                'icon' => 'b2b',
                'color' => '#0891B2',
                'type' => 'b2b_directory',
                'coverage' => 'manual',
                'url' => 'https://www.k2b2b.com/',
                'register_url' => 'https://www.k2b2b.com/',
                'cert_required' => '企业免费注册商铺',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '免费B2B信息发布，覆盖工业/家居/电子全品类，信息展示+联系方式直连',
            ],
            'lswang' => [
                'key' => 'lswang',
                'name' => '领商网',
                'icon' => 'b2b',
                'color' => '#7C3AED',
                'type' => 'b2b_directory',
                'coverage' => 'manual',
                'url' => 'https://www.lswang.net/',
                'register_url' => 'https://www.lswang.net/',
                'cert_required' => '企业免费注册商铺',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '综合免费B2B产品推广站，覆盖电气/家居/建材/商务服务，自然搜索收录获客',
            ],
            'wanjiabiz' => [
                'key' => 'wanjiabiz',
                'name' => '万家商务网',
                'icon' => 'b2b',
                'color' => '#DC2626',
                'type' => 'b2b_directory',
                'coverage' => 'manual',
                'url' => 'https://www.wanjiabiz.com/',
                'register_url' => 'https://www.wanjiabiz.com/',
                'cert_required' => '企业免费开通店铺',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '综合商贸B2B，产品供应库+企业黄页，覆盖家居/建材/电子/化工',
            ],
            'jiuzhouziyuan' => [
                'key' => 'jiuzhouziyuan',
                'name' => '九州资源网',
                'icon' => 'factory',
                'color' => '#15803D',
                'type' => 'b2b_directory',
                'coverage' => 'manual',
                'url' => 'https://www.jiuzhouziyuan.com/',
                'register_url' => 'https://www.jiuzhouziyuan.com/',
                'cert_required' => '企业免费发布供应信息',
                'cited_by_llms' => ['百度AI搜索', '文心一言'],
                'citation_weight' => 'medium',
                'description' => '工业属性B2B，环保设备/化工/建材/五金类供应商信息，收录大量生产型企业',
            ],
            'zhizhu35' => [
                'key' => 'zhizhu35',
                'name' => '蜘蛛商务网',
                'icon' => 'b2b',
                'color' => '#BE185D',
                'type' => 'b2b_marketplace',
                'coverage' => 'manual',
                'url' => 'https://www.zhizhu35.com/',
                'register_url' => 'https://www.zhizhu35.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '豆包', '百度AI搜索'],
                'citation_weight' => 'high',
                'description' => '30万+生产厂家、200万+贸易商入驻，累计超300万次询盘，中小企业全网营销',
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
     * 按引用权重排序的平台列表（最重要的排前面）。
     *
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
                $updateData[$field] = $data[$field];
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
}
