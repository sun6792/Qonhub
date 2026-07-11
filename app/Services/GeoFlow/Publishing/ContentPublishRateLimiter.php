<?php

namespace App\Services\GeoFlow\Publishing;

use App\Models\ContentPublisherAccount;
use Illuminate\Support\Facades\Cache;

/**
 * 内容发布频率控制器。
 *
 * 实现单账号发布间隔、单日发布上限、随机错峰调度。
 * 基于 Laravel Cache（复用 Redis），无额外依赖。
 */
class ContentPublishRateLimiter
{
    /**
     * 获取建议的延迟秒数（错峰调度）。
     */
    public function suggestedDelaySeconds(ContentPublisherAccount $account): int
    {
        // 基础间隔 + 随机抖动（±30%）
        $baseInterval = max(60, $account->publish_interval_seconds);
        $jitter = random_int(-30, 30) / 100; // -30% ~ +30%

        return (int) round($baseInterval * (1 + $jitter));
    }

    /**
     * 检查账号是否可以立即发布。
     */
    public function canPublishNow(ContentPublisherAccount $account): bool
    {
        if ($account->next_available_at !== null && $account->next_available_at->isFuture()) {
            return false;
        }

        return $account->isAvailable();
    }

    /**
     * 获取账号需要等待的秒数。
     */
    public function waitSeconds(ContentPublisherAccount $account): int
    {
        if ($account->next_available_at === null) {
            return 0;
        }

        return max(0, (int) $account->next_available_at->diffInSeconds(now()) + 1);
    }

    /**
     * 确保全局发布锁（避免同 workspace 同平台并发发布）。
     */
    public function acquireGlobalLock(int $workspaceId, string $platformKey, int $timeoutSeconds = 30): bool
    {
        $lockKey = "publish_lock:{$workspaceId}:{$platformKey}";

        return Cache::lock($lockKey, $timeoutSeconds)->get();
    }

    public function releaseGlobalLock(int $workspaceId, string $platformKey): void
    {
        $lockKey = "publish_lock:{$workspaceId}:{$platformKey}";
        Cache::lock($lockKey)->release();
    }
}
