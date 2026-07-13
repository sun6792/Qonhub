<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * B2B 锚点平台认证记录。
 *
 * 每条记录追踪一个企业在特定 B2B 平台的认证进度。
 * 认证完成后的平台页面 URL 可被大模型抓取引用。
 *
 * @property int $id
 * @property int $enterprise_profile_id
 * @property string $anchor_platform_key
 * @property string $certification_status
 */
class EnterpriseAnchorCertification extends Model
{
    protected $table = 'enterprise_anchor_certifications';

    protected $fillable = [
        'enterprise_profile_id',
        'anchor_platform_key',
        'platform_account_id', 'platform_page_url',
        'certification_status',
        'certified_by', 'certified_at', 'expires_at',
        'verification_notes', 'last_sync_at',
        'metadata', 'rpa_task_id',
    ];

    protected $casts = [
        'certified_at' => 'datetime',
        'expires_at' => 'date',
        'last_sync_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ─── 关系 ────────────────────────────────────────

    public function profile(): BelongsTo
    {
        return $this->belongsTo(EnterpriseProfile::class, 'enterprise_profile_id');
    }

    public function certifier(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'certified_by');
    }

    // ─── 便捷方法 ────────────────────────────────────

    public function isCertified(): bool
    {
        return $this->certification_status === 'certified';
    }

    public function isExpired(): bool
    {
        if ($this->certification_status === 'expired') {
            return true;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return true;
        }
        return false;
    }

    public function statusLabel(): string
    {
        return match ($this->certification_status) {
            'certified' => $this->isExpired() ? '已过期' : '已认证',
            'pending' => '待认证',
            'expired' => '已过期',
            'rejected' => '未通过',
            default => '未知',
        };
    }

    public function statusColor(): string
    {
        return match (true) {
            $this->isExpired() => 'gray',
            $this->certification_status === 'certified' => 'green',
            $this->certification_status === 'pending' => 'amber',
            $this->certification_status === 'rejected' => 'red',
            default => 'gray',
        };
    }
}
