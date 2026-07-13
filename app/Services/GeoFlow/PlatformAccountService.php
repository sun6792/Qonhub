<?php

namespace App\Services\GeoFlow;

use App\Models\ClientPlatformAccount;
use App\Models\Workspace;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Support\Collection;

class PlatformAccountService
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
    ) {}

    /**
     * @return Collection<int, ClientPlatformAccount>
     */
    public function listForWorkspace(int $workspaceId): Collection
    {
        $accounts = ClientPlatformAccount::query()
            ->where('workspace_id', $workspaceId)
            ->get()
            ->keyBy('platform_key');

        $supported = ClientPlatformAccount::supportedPlatforms();
        $result = collect();

        foreach ($supported as $key => $info) {
            $account = $accounts->get($key);
            $result->push([
                'platform_key' => $key,
                'name' => $info['name'] ?? $key,
                'icon' => $info['icon'] ?? 'globe',
                'color' => $info['color'] ?? '#6b7280',
                'login_url' => $info['login_url'] ?? '#',
                'connected' => $account !== null,
                'status' => $account?->status ?? 'not_connected',
                'account_name' => $account?->platform_account_name ?? null,
                'last_verified_at' => $account?->last_verified_at?->diffForHumans() ?? null,
                'expires_at' => $account?->expires_at?->toDateString() ?? null,
            ]);
        }

        return $result;
    }

    /**
     * 存储加密的Cookie凭证。
     */
    public function storeCredential(int $workspaceId, string $platformKey, string $cookieValue, ?string $displayName = null): ClientPlatformAccount
    {
        $encrypted = $this->apiKeyCrypto->encrypt($cookieValue);

        return ClientPlatformAccount::query()->updateOrCreate(
            ['workspace_id' => $workspaceId, 'platform_key' => $platformKey],
            [
                'credential_ciphertext' => $encrypted,
                'platform_account_name' => $displayName,
                'status' => 'active',
                'last_verified_at' => now(),
                'expires_at' => now()->addDays(30),
                'last_error_message' => null,
            ]
        );
    }

    public function revokeCredential(int $workspaceId, string $platformKey): void
    {
        ClientPlatformAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('platform_key', $platformKey)
            ->update([
                'status' => 'revoked',
                'credential_ciphertext' => null,
                'last_error_message' => null,
            ]);
    }

    public function markExpired(int $workspaceId, string $platformKey): void
    {
        ClientPlatformAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('platform_key', $platformKey)
            ->update(['status' => 'expired']);
    }

    /**
     * @return array{connected:int, total:int}
     */
    public function connectionStats(int $workspaceId): array
    {
        $total = count(ClientPlatformAccount::supportedPlatforms());
        $connected = ClientPlatformAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->count();

        return ['connected' => $connected, 'total' => $total];
    }
}
