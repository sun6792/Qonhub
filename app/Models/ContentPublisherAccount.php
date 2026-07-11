<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 统一内容发布账号池。
 *
 * 支持 OAuth Token / Cookie / Password 三种凭证类型。
 * 凭证复用 ApiKeyCrypto 加密存储，与现有体系一致。
 */
class ContentPublisherAccount extends Model
{
    use SoftDeletes;

    protected $table = 'content_publisher_accounts';

    protected $fillable = [
        'workspace_id', 'platform_key', 'platform_type', 'platform_name',
        'account_name', 'account_id_on_platform',
        'credential_type', 'credential_ciphertext', 'credential_metadata',
        'status', 'health_status', 'last_health_check_at', 'last_error_message',
        'consecutive_failures', 'daily_publish_count', 'daily_reset_at',
        'publish_interval_seconds', 'daily_publish_limit', 'total_publish_count',
        'last_publish_at', 'next_available_at',
        'bound_ip', 'bound_fingerprint_id', 'requires_rpa',
        'risk_level', 'success_rate',
        'oauth_app_id', 'oauth_extra',
        'created_by_admin_id', 'sort_order', 'notes',
    ];

    protected $casts = [
        'credential_metadata' => 'array',
        'oauth_extra' => 'array',
        'last_health_check_at' => 'datetime',
        'last_publish_at' => 'datetime',
        'next_available_at' => 'datetime',
        'daily_reset_at' => 'datetime',
        'requires_rpa' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    // ── 便捷判断 ──

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isHealthy(): bool
    {
        return $this->health_status === 'healthy';
    }

    public function isAvailable(): bool
    {
        if (! $this->isActive() || ! $this->isHealthy()) {
            return false;
        }

        if ($this->next_available_at !== null && $this->next_available_at->isFuture()) {
            return false;
        }

        $this->resetDailyCountIfNeeded();

        if ($this->daily_publish_count >= $this->daily_publish_limit) {
            return false;
        }

        return true;
    }

    public function resetDailyCountIfNeeded(): void
    {
        if ($this->daily_reset_at === null || $this->daily_reset_at->isPast()) {
            $this->forceFill([
                'daily_publish_count' => 0,
                'daily_reset_at' => now()->endOfDay(),
            ])->save();
        }
    }

    public function recordPublish(): void
    {
        $this->resetDailyCountIfNeeded();
        $this->increment('daily_publish_count');
        $this->increment('total_publish_count');
        $this->forceFill([
            'last_publish_at' => now(),
            'next_available_at' => now()->addSeconds($this->publish_interval_seconds),
            'consecutive_failures' => 0,
        ])->save();
    }

    public function recordFailure(string $errorMessage): void
    {
        $this->forceFill([
            'last_error_message' => mb_substr($errorMessage, 0, 500),
            'consecutive_failures' => $this->consecutive_failures + 1,
            'health_status' => $this->consecutive_failures >= 3 ? 'degraded' : $this->health_status,
        ])->save();

        if ($this->consecutive_failures >= 5) {
            $this->forceFill(['status' => 'locked', 'health_status' => 'unhealthy'])->save();
        }
    }
}
