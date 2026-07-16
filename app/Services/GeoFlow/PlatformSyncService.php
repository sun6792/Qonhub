<?php

namespace App\Services\GeoFlow;

use App\Models\ClientPlatformAccount;
use App\Models\ContentPublisherAccount;
use App\Models\EnterpriseAnchorCertification;
use App\Models\EnterpriseProfile;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 统一平台账号同步服务。
 *
 * 核心职责：一处绑定，三处同步。
 *   - ClientPlatformAccount（客户端看板展示）
 *   - ContentPublisherAccount（发布管道凭证）
 *   - EnterpriseAnchorCertification（信息锚点认证）
 *
 * 无论从哪里触发绑定（客户端 / 运营助手 / RPA引擎回传），都走这个方法。
 */
class PlatformSyncService
{
    public function __construct(
        private readonly ApiKeyCrypto $crypto,
    ) {}

    /**
     * 统一绑定/更新平台。
     *
     * @param  array{platform_key:string, platform_name?:string, credential?:string, source?:string}  $payload
     */
    public function syncBinding(int $workspaceId, array $payload): array
    {
        $platformKey = $payload['platform_key'];
        $platformName = $payload['platform_name'] ?? $platformKey;
        $source = $payload['source'] ?? 'manual';
        $result = ['platform_key' => $platformKey, 'workspace_id' => $workspaceId];

        // 三表写入包裹在事务中：任意一步失败则全部回滚
        return DB::transaction(function () use ($workspaceId, $platformKey, $platformName, $source, $payload, $result) {
            // ═══ 1. ClientPlatformAccount（客户端可见）═══════
            $clientData = [
                'platform_account_name' => $platformName,
                'status' => 'active',
                'last_verified_at' => now(),
                'expires_at' => now()->addDays(30),
            ];
            if (! empty($payload['credential'])) {
                $clientData['credential_ciphertext'] = $this->crypto->encrypt($payload['credential']);
            }
            $cpa = ClientPlatformAccount::query()->updateOrCreate(
                ['workspace_id' => $workspaceId, 'platform_key' => $platformKey],
                $clientData
        );
        $result['client_account_id'] = (int) $cpa->id;
        $result['client_account_status'] = $cpa->status;

        // ═══ 2. ContentPublisherAccount（发布管道）═══════
        $publisherData = [
            'platform_key' => $platformKey,
            'account_name' => $platformName,
            'credential_type' => $this->inferCredentialType($platformKey),
            'status' => 'active',
            'last_health_checked_at' => now(),
            'last_health_status' => 'healthy',
        ];
        if (! empty($payload['credential'])) {
            $publisherData['credential_ciphertext'] = $this->crypto->encrypt($payload['credential']);
        }
        $pubAccount = ContentPublisherAccount::query()->updateOrCreate(
            ['workspace_id' => $workspaceId, 'platform_key' => $platformKey],
            $publisherData
        );
        $result['publisher_account_id'] = (int) $pubAccount->id;
        $result['publisher_account_status'] = $pubAccount->status;

        // ═══ 3. EnterpriseAnchorCertification（锚点）═══════
        $profile = EnterpriseProfile::query()->where('workspace_id', $workspaceId)->first();
        if ($profile) {
            $cert = EnterpriseAnchorCertification::query()->updateOrCreate(
                ['enterprise_profile_id' => (int) $profile->id, 'anchor_platform_key' => $platformKey],
                [
                    'certification_status' => 'certified',
                    'certified_at' => now(),
                    'platform_account_id' => $platformName,
                ]
            );
            $result['certification_id'] = (int) $cert->id;
            $result['certification_status'] = $cert->certification_status;
        }

        Log::info('PlatformSync: 三处同步完成', [
            'workspace_id' => $workspaceId,
            'platform' => $platformKey,
            'source' => $source,
            'client_id' => $result['client_account_id'] ?? 0,
            'publisher_id' => $result['publisher_account_id'] ?? 0,
            'cert_id' => $result['certification_id'] ?? 0,
        ]);

        return $result;
        }); // DB::transaction 结束
    }

    /**
     * 根据平台 key 推断凭证类型。
     */
    private function inferCredentialType(string $platformKey): string
    {
        return match ($platformKey) {
            'toutiao' => 'oauth_token',       // 头条走 OAuth
            'media_box_api' => 'password',     // 媒介盒子 API Key
            default => 'cookie',               // 百家号/搜狐/B2B 等走 RPA Cookie
        };
    }

    /**
     * 获取某 workspace 下所有平台的统一状态（供运营助手 dashboard）。
     */
    public function getUnifiedStatus(int $workspaceId): array
    {
        $allPlatforms = ClientPlatformAccount::supportedPlatforms();
        $dbAccounts = ClientPlatformAccount::query()
            ->where('workspace_id', $workspaceId)
            ->get()
            ->keyBy('platform_key');
        $pubAccounts = ContentPublisherAccount::query()
            ->where('workspace_id', $workspaceId)
            ->get()
            ->keyBy('platform_key');

        $result = [];
        foreach ($allPlatforms as $key => $info) {
            $db = $dbAccounts->get($key);
            $pub = $pubAccounts->get($key);
            $cacheFile = base_path("rpa-engine/storage/states/{$workspaceId}/{$key}.json");
            $hasCache = file_exists($cacheFile) && filesize($cacheFile) > 100;

            $result[] = [
                'key' => $key,
                'name' => $info['name'] ?? $key,
                'icon' => $info['icon'] ?? 'globe',
                'color' => $info['color'] ?? '#666',
                'login_url' => $info['login_url'] ?? '',
                // DB 状态
                'db_status' => $db?->status ?? 'none',
                'db_bound' => $db !== null && $db->isActive(),
                // 缓存状态
                'cache_valid' => $hasCache,
                // 综合状态（四态）
                'status' => $this->computeStatus($db, $hasCache),
                'status_label' => $this->computeStatusLabel($db, $hasCache),
                // 发布就绪
                'publish_ready' => $pub !== null && $pub->status === 'active',
            ];
        }

        return $result;
    }

    /**
     * 四态判定：DB + 缓存的交叉矩阵。
     */
    private function computeStatus($dbAccount, bool $hasCache): string
    {
        $dbActive = $dbAccount !== null && $dbAccount->isActive();

        if ($dbActive && $hasCache) return 'ready';          // 🟢 可分发
        if ($dbActive && ! $hasCache) return 'need_login';   // 🟡 需重新登录
        if (! $dbActive && $hasCache) return 'need_bind';    // 🟠 缓存有效但DB未绑定
        return 'unbound';                                     // ⚪ 未绑定
    }

    private function computeStatusLabel($dbAccount, bool $hasCache): string
    {
        return match ($this->computeStatus($dbAccount, $hasCache)) {
            'ready' => '🟢 可分发',
            'need_login' => '🟡 需登录',
            'need_bind' => '🟠 待绑定',
            default => '⚪ 未绑定',
        };
    }
}
