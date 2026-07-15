<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;

/**
 * 大模型熔断器 — Redis 驱动，按 (workspace, provider) 维度熔断。
 *
 * 策略：5分钟内连续失败3次 → 熔断60秒 → 半开状态（允许1次探测）
 */
class CircuitBreaker
{
    private const FAILURE_THRESHOLD = 3;
    private const COOLDOWN_SECONDS = 60;
    private const WINDOW_SECONDS = 300;

    public function __construct(
        private readonly int $workspaceId,
    ) {}

    /**
     * 检查是否熔断中。
     */
    public function isOpen(string $providerCode): bool
    {
        $failures = (int) Cache::get($this->failuresKey($providerCode), 0);

        if ($failures >= self::FAILURE_THRESHOLD) {
            $openedAt = (int) Cache::get($this->openedAtKey($providerCode), 0);
            if ($openedAt > 0 && (time() - $openedAt) < self::COOLDOWN_SECONDS) {
                return true; // 熔断中
            }
            // 冷却期过 → 半开状态，允许通过
        }

        return false;
    }

    /**
     * 记录一次成功。
     */
    public function recordSuccess(string $providerCode): void
    {
        Cache::forget($this->failuresKey($providerCode));
        Cache::forget($this->openedAtKey($providerCode));
    }

    /**
     * 记录一次失败。
     */
    public function recordFailure(string $providerCode): void
    {
        $key = $this->failuresKey($providerCode);
        $failures = (int) Cache::get($key, 0);
        Cache::put($key, $failures + 1, self::WINDOW_SECONDS);

        if ($failures + 1 >= self::FAILURE_THRESHOLD) {
            Cache::put($this->openedAtKey($providerCode), time(), self::WINDOW_SECONDS);
        }
    }

    private function failuresKey(string $providerCode): string
    {
        return "circuit:{$this->workspaceId}:{$providerCode}:failures";
    }

    private function openedAtKey(string $providerCode): string
    {
        return "circuit:{$this->workspaceId}:{$providerCode}:opened_at";
    }
}
