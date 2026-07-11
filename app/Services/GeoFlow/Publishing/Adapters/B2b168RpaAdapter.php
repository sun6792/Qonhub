<?php

namespace App\Services\GeoFlow\Publishing\Adapters;

use App\Models\Article;
use App\Models\EnterpriseProfile;
use RuntimeException;

/**
 * 八方资源网（b2b168.com）RPA 适配器。
 *
 * 核心定位：企业认证入驻（对齐摘星方舟锚点模式）。
 *   主流程：一键认证 → 自动注册 + 企业认证 + 开通店铺 → 抓取店铺 URL → 写入锚点
 *   辅流程：商机内容发布（doPublish 保留，作为后续增值能力）
 *
 * B2B 认证流程（基于对八方资源网注册步骤的分析）：
 * 1. 打开注册页，填写账号密码
 * 2. 选择企业认证类型（个体工商户/企业）
 * 3. 填写企业工商信息（统一社会信用代码自动校验）
 * 4. 上传营业执照图片
 * 5. 填写联系方式+主营产品
 * 6. 提交审核 → 等待平台审核通过
 * 7. 获取企业店铺 URL
 *
 * 当前实现：通过 RPA 引擎提交认证任务，异步等待结果。
 */
class B2b168RpaAdapter extends GenericRpaAdapter
{
    public function platformKey(): string
    {
        return 'b2b168';
    }

    // ── 主流程：企业认证入驻（doRegister） ──────────────────

    /**
     * 八方资源网企业认证入驻。
     *
     * 将 EnterpriseProfile 的 NAP+W 数据自动填充到注册表单，
     * 通过 RPA 引擎完成注册认证全流程。
     */
    protected function doRegister(EnterpriseProfile $profile, array $enterpriseData): array
    {
        // 构造 RPA 认证任务
        $rpaTask = [
            'platform' => $this->platformKey(),
            'platform_name' => '八方资源网',
            'action' => 'register_and_certify',
            'account' => [
                'username' => $enterpriseData['register_username'],
                'credential' => $enterpriseData['register_credential'],
                'credential_type' => $this->account->credential_type,
            ],
            'enterprise' => [
                'company_name' => $enterpriseData['company_name'],
                'credit_code' => $enterpriseData['credit_code'],
                'legal_person' => $enterpriseData['legal_person'],
                'registered_capital' => $enterpriseData['registered_capital'],
                'establishment_date' => $enterpriseData['establishment_date'],
                'business_scope' => $enterpriseData['business_scope'],
                'province' => $enterpriseData['province'],
                'city' => $enterpriseData['city'],
                'address' => $enterpriseData['address'],
                'phone' => $enterpriseData['phone'],
                'email' => $enterpriseData['email'],
                'website' => $enterpriseData['website'],
                'industry' => $enterpriseData['industry'],
                'products' => $enterpriseData['products'],
            ],
            'options' => [
                'bound_ip' => $this->account->bound_ip,
                'fingerprint_id' => $this->account->bound_fingerprint_id,
                'timeout_seconds' => 180,
                'wait_for_review' => false, // 不等审核，先回传 pending 状态
            ],
        ];

        // 调用 RPA 引擎（统一客户端，自动轮询结果）
        return $this->rpa()->executeTask($rpaTask);
    }

    /**
     * 适配 EnterpriseProfile 为八方资源网专用字段名。
     */
    protected function adaptEnterpriseProfile(EnterpriseProfile $profile): array
    {
        $base = parent::adaptEnterpriseProfile($profile);

        // 八方资源网特有字段映射
        return array_merge($base, [
            'gsmc' => $base['company_name'],          // 公司名称
            'tyxydm' => $base['credit_code'],         // 统一信用代码
            'frdb' => $base['legal_person'],          // 法定代表人
            'zczb' => $base['registered_capital'],    // 注册资本
            'jyfw' => $base['business_scope'],        // 经营范围
            'sssf' => $base['province'],              // 所属省份
            'sscs' => $base['city'],                  // 所属城市
            'xxdz' => $base['address'],               // 详细地址
            'lxdh' => $base['phone'],                 // 联系电话
            'dzyx' => $base['email'],                 // 电子邮箱
            'qywz' => $base['website'],               // 企业网站
            'zycp' => $base['products'],              // 主营产品
            'cate_id' => $this->resolveCategoryId($base['industry']),
        ]);
    }

    // ── 辅流程：商机内容发布（保留，不修改） ──────────────

    protected function doPublish(Article $article, array $adaptedContent): array
    {
        $profile = EnterpriseProfile::query()
            ->where('workspace_id', (int) $this->account->workspace_id)
            ->first();

        if (! $profile || empty($profile->company_full_name)) {
            throw new RuntimeException('请先完善企业档案（公司全称必填）');
        }

        $adaptedContent['company_name'] = $profile->company_full_name;
        $adaptedContent['company_address'] = $profile->company_address;
        $adaptedContent['contact_phone'] = $profile->company_phone;
        $adaptedContent['contact_person'] = $profile->legal_person;
        $adaptedContent['products'] = is_array($profile->products_services)
            ? implode(', ', $profile->products_services)
            : '';

        return parent::doPublish($article, $adaptedContent);
    }

    protected function adaptFormat(array $content): array
    {
        return $content;
    }

    // ── 辅助 ──────────────────────────────────────────────

    private function registerFail(string $message, array $raw): array
    {
        return [
            'success' => false,
            'shop_url' => '',
            'account_id' => '',
            'raw_response' => array_merge($raw, ['error' => $message]),
            'engine' => 'rpa',
        ];
    }

    /**
     * 根据行业名称解析为八方资源网的类目 ID。
     */
    private function resolveCategoryId(string $industry): string
    {
        // 行业 → 类目映射表（后续可从数据库配置加载）
        $map = [
            '运动器材' => 'sports',
            '体育用品' => 'sports',
            '建材' => 'building',
            '化工' => 'chemical',
            '电子' => 'electronics',
            '服装' => 'clothing',
            '食品' => 'food',
        ];

        foreach ($map as $keyword => $cateId) {
            if (mb_strpos($industry, $keyword) !== false) {
                return $cateId;
            }
        }

        return 'other'; // 默认类目
    }
}
