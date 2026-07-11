<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 企业档案：绑定到一个工作空间，存储所有 B2B 信息锚点所需的企业资料。
 *
 * NAP+W 一致性说明：
 * - Name（公司全称/简称）→ Company Name
 * - Address（详细地址）→ Company Address
 * - Phone（联系电话）→ Company Phone
 * - Website（官网）→ Company Website
 * 以上四者 + 统一社会信用代码 在所有 B2B 平台上保持完全一致，大模型引用时才不会产生歧义。
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $company_full_name
 * @property string $unified_social_credit_code
 * @property string $verification_status
 */
class EnterpriseProfile extends Model
{
    protected $table = 'enterprise_profiles';

    protected $fillable = [
        'workspace_id',
        'company_full_name', 'company_short_name',
        'unified_social_credit_code', 'legal_person',
        'registered_capital', 'establishment_date',
        'business_scope',
        'company_province', 'company_city', 'company_address',
        'company_phone', 'company_email', 'company_website',
        'industry', 'products_services',
        'business_license_path', 'company_logo_path',
        'nap_consistency_checked',
        'verification_status', 'verified_by', 'verified_at',
    ];

    protected $casts = [
        'establishment_date' => 'date',
        'verified_at' => 'datetime',
        'nap_consistency_checked' => 'boolean',
        'products_services' => 'array',
    ];

    // ─── 关系 ────────────────────────────────────────

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(EnterpriseAnchorCertification::class, 'enterprise_profile_id');
    }

    // ─── 便捷方法 ────────────────────────────────────

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function isNapConsistent(): bool
    {
        return $this->nap_consistency_checked;
    }

    /**
     * 获取认证完成的平台数量。
     */
    public function certifiedPlatformCount(): int
    {
        return $this->certifications()
            ->where('certification_status', 'certified')
            ->count();
    }
}
