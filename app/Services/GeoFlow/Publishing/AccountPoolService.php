<?php

namespace App\Services\GeoFlow\Publishing;

use App\Models\ContentPublisherAccount;
use App\Models\Workspace;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 账号池管理服务。
 *
 * 核心职责：账号 CRUD、凭证加解密、健康检测、轮换调度。
 * 严格 Workspace 隔离，所有查询自动带 workspace_id 过滤。
 */
class AccountPoolService
{
    public function __construct(
        private readonly ApiKeyCrypto $crypto,
    ) {}

    // ── 账号 CRUD ─────────────────────────────────────────

    /**
     * @return Collection<int, ContentPublisherAccount>
     */
    public function listForWorkspace(int $workspaceId, ?string $platformType = null): Collection
    {
        $query = ContentPublisherAccount::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($platformType !== null) {
            $query->where('platform_type', $platformType);
        }

        return $query->get();
    }

    /**
     * 获取某平台下所有可用账号（按健康度排序）。
     *
     * @return Collection<int, ContentPublisherAccount>
     */
    public function getAvailableAccounts(int $workspaceId, string $platformKey): Collection
    {
        return ContentPublisherAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('platform_key', $platformKey)
            ->where('status', 'active')
            ->where('health_status', '!=', 'unhealthy')
            ->orderByRaw("
                CASE health_status
                    WHEN 'healthy' THEN 0
                    WHEN 'degraded' THEN 1
                    ELSE 2
                END
            ")
            ->orderBy('consecutive_failures')
            ->orderBy('daily_publish_count')
            ->get()
            ->filter(fn (ContentPublisherAccount $a) => $a->isAvailable());
    }

    /**
     * 智能选择最佳可用账号。
     *
     * 优先级：risk_level(low→medium→high) → daily_publish_count(最少) → last_publish_at(最久)
     */
    public function selectBestAccount(int $workspaceId, string $platformKey): ?ContentPublisherAccount
    {
        return $this->getAvailableAccounts($workspaceId, $platformKey)
            ->sortBy([
                fn ($a, $b) => $this->riskWeight($a) <=> $this->riskWeight($b),
                fn ($a, $b) => ($a->daily_publish_count ?? 0) <=> ($b->daily_publish_count ?? 0),
                fn ($a, $b) => ($a->last_publish_at?->timestamp ?? PHP_INT_MAX) <=> ($b->last_publish_at?->timestamp ?? PHP_INT_MAX),
            ])
            ->first();
    }

    /**
     * 账号轮换：当前账号不可用时切换到下一个。
     * 使用悲观行锁防止并发递增导致的假锁死。
     */
    public function rotateToNext(int $workspaceId, string $platformKey, int $currentAccountId): ?ContentPublisherAccount
    {
        return DB::transaction(function () use ($workspaceId, $platformKey, $currentAccountId) {
            // 悲观锁读取当前账号，防止并发递增
            $current = ContentPublisherAccount::query()
                ->whereKey($currentAccountId)
                ->lockForUpdate()
                ->first();

            if ($current) {
                $current->increment('daily_publish_count');
                $current->forceFill(['last_publish_at' => now()])->save();
            }

            return ContentPublisherAccount::query()
                ->where('workspace_id', $workspaceId)
                ->where('platform_key', $platformKey)
                ->where('status', 'active')
                ->where('health_status', '!=', 'unhealthy')
                ->whereKeyNot($currentAccountId)
                ->where(function ($q) {
                    $q->whereRaw('COALESCE(daily_publish_count, 0) < COALESCE(daily_publish_limit, 5)');
                })
                ->orderBy('risk_level')
                ->orderBy('daily_publish_count')
                ->orderBy('last_publish_at')
                ->first();
        });
    }

    private function riskWeight(ContentPublisherAccount $account): int
    {
        return match ($account->risk_level) {
            'low' => 0,
            'medium' => 1,
            'high' => 2,
            default => 1,
        };
    }

    /**
     * 创建账号（自动加密凭证）。
     */
    public function createAccount(Workspace $workspace, array $data): ContentPublisherAccount
    {
        $credential = $data['credential_plaintext'] ?? '';
        if ($credential === '') {
            throw new RuntimeException('凭证不能为空');
        }

        $account = ContentPublisherAccount::query()->create([
            'workspace_id' => (int) $workspace->id,
            'platform_key' => (string) ($data['platform_key'] ?? ''),
            'platform_type' => (string) ($data['platform_type'] ?? 'self_media'),
            'platform_name' => (string) ($data['platform_name'] ?? ''),
            'account_name' => (string) ($data['account_name'] ?? ''),
            'account_id_on_platform' => (string) ($data['account_id_on_platform'] ?? ''),
            'credential_type' => (string) ($data['credential_type'] ?? 'cookie'),
            'credential_ciphertext' => $this->crypto->encrypt($credential),
            'credential_metadata' => $data['credential_metadata'] ?? null,
            'publish_interval_seconds' => (int) ($data['publish_interval_seconds'] ?? 120),
            'daily_publish_limit' => (int) ($data['daily_publish_limit'] ?? 20),
            'bound_ip' => (string) ($data['bound_ip'] ?? ''),
            'bound_fingerprint_id' => (string) ($data['bound_fingerprint_id'] ?? ''),
            'requires_rpa' => (bool) ($data['requires_rpa'] ?? false),
            'oauth_app_id' => (string) ($data['oauth_app_id'] ?? ''),
            'oauth_extra' => $data['oauth_extra'] ?? null,
            'created_by_admin_id' => (int) ($data['created_by_admin_id'] ?? 0),
            'notes' => (string) ($data['notes'] ?? ''),
        ]);

        return $account;
    }

    /**
     * 获取解密后的凭证明文（仅内部使用，禁止输出到前端/日志）。
     */
    public function decryptCredential(ContentPublisherAccount $account): string
    {
        $ciphertext = $account->credential_ciphertext;
        if (empty($ciphertext)) {
            throw new RuntimeException("账号 {$account->account_name} 无凭证");
        }

        return $this->crypto->decrypt($ciphertext);
    }

    /**
     * 更新账号凭证。
     */
    public function updateCredential(ContentPublisherAccount $account, string $newCredential): void
    {
        $account->forceFill([
            'credential_ciphertext' => $this->crypto->encrypt($newCredential),
            'consecutive_failures' => 0,
            'health_status' => 'healthy',
            'last_error_message' => '',
        ])->save();
    }

    // ── 健康检测 ──────────────────────────────────────────

    /**
     * 对账号执行健康检测。
     */
    public function healthCheck(ContentPublisherAccount $account): array
    {
        try {
            $adapter = PlatformAdapterFactory::create($account);
            $result = $adapter->checkHealth();

            $account->forceFill([
                'health_status' => $result['healthy'] ? 'healthy' : 'degraded',
                'last_health_check_at' => now(),
                'last_error_message' => $result['healthy'] ? '' : $result['message'],
            ])->save();

            return $result;
        } catch (\Throwable $e) {
            $account->forceFill([
                'health_status' => 'unhealthy',
                'last_health_check_at' => now(),
                'last_error_message' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            return ['healthy' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 批量健康检测。
     */
    public function batchHealthCheck(int $workspaceId): array
    {
        $accounts = $this->listForWorkspace($workspaceId);
        $results = [];

        foreach ($accounts as $account) {
            $results[$account->id] = $this->healthCheck($account);
        }

        return $results;
    }

    // ── 轮换策略 ──────────────────────────────────────────

    /**
     * 当账号连续失败超过阈值时，自动切换到备用账号。
     *
     * @return ContentPublisherAccount|null 新的可用账号，null 表示无可用账号
     */
    public function rotateIfNeeded(ContentPublisherAccount $failedAccount): ?ContentPublisherAccount
    {
        if ($failedAccount->consecutive_failures < 3) {
            return $failedAccount;
        }

        $alternatives = $this->getAvailableAccounts(
            (int) $failedAccount->workspace_id,
            $failedAccount->platform_key
        );

        return $alternatives->where('id', '!=', $failedAccount->id)->first();
    }
}
