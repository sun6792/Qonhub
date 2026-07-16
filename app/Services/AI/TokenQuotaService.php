<?php

namespace App\Services\AI;

use App\Models\WorkspaceAiTokenQuota;
use Illuminate\Support\Facades\Cache;

/**
 * 租户 Token 额度管控服务。
 * 按 (workspace_id, provider_code) 维度控制月消耗。
 */
class TokenQuotaService
{
    /**
     * 检查是否还有额度。
     */
    public function hasQuota(int $workspaceId, string $providerCode): bool
    {
        try {
            $quota = $this->getQuota($workspaceId, $providerCode);
            if ($quota['quota_monthly'] <= 0) {
                return true; // 0 = 不限
            }
            $this->autoReset($workspaceId, $providerCode);
            return $quota['used_this_month'] < $quota['quota_monthly'];
        } catch (\Throwable) {
            return true; // 配额服务异常时不阻塞
        }
    }

    /**
     * 扣减额度（调用成功后调用）。
     */
    public function deduct(int $workspaceId, string $providerCode, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        try {
            WorkspaceAiTokenQuota::query()
                ->where('workspace_id', $workspaceId)
                ->where('provider_code', $providerCode)
                ->increment('used_this_month', $tokens);
        } catch (\Throwable) { /* DB quota failure shouldn't block */ }

        try {
            Cache::increment("quota:{$workspaceId}:{$providerCode}", $tokens);
        } catch (\Throwable) { /* Redis cache failure shouldn't block */ }
    }

    /**
     * 获取额度信息。
     */
    private function getQuota(int $workspaceId, string $providerCode): array
    {
        $key = "quota:{$workspaceId}:{$providerCode}";

        return Cache::remember($key, 60, function () use ($workspaceId, $providerCode) {
            $row = WorkspaceAiTokenQuota::query()
                ->where('workspace_id', $workspaceId)
                ->where('provider_code', $providerCode)
                ->first();

            return [
                'quota_monthly' => (int) ($row->quota_monthly ?? 0),
                'used_this_month' => (int) ($row->used_this_month ?? 0),
            ];
        });
    }

    /**
     * 跨月自动重置。
     */
    private function autoReset(int $workspaceId, string $providerCode): void
    {
        $row = WorkspaceAiTokenQuota::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider_code', $providerCode)
            ->first();

        if (! $row) {
            return;
        }

        $now = now();
        if ($now->month !== $row->reset_at?->month || $now->year !== $row->reset_at?->year) {
            $row->forceFill([
                'used_this_month' => 0,
                'reset_at' => $now,
            ])->save();
            Cache::forget("quota:{$workspaceId}:{$providerCode}");
        }
    }
}
