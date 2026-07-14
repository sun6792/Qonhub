<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\ClientPlatformAccount;
use App\Models\ContentPublisherAccount;
use App\Models\Workspace;
use App\Services\GeoFlow\PlatformSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OAuth 授权回调控制器。
 *
 * 处理头条等平台的 OAuth 回调，自动获取并加密存储 access_token。
 * 阿里云部署后，回调地址为 https://你的域名/oauth/callback/{platform}
 */
class OAuthController extends Controller
{
    /**
     * GET /oauth/authorize/{platform}
     * 发起 OAuth 授权：构造授权 URL 并 302 跳转到平台授权页。
     */
    public function redirect(string $platform, Request $request): RedirectResponse
    {
        // 从 query 参数获取 workspace context（客户端跳转时附带）
        $wsId = (int) $request->query('ws', 0);
        if ($wsId <= 0) {
            return redirect()->back()->withErrors('缺少工作空间参数');
        }

        return match ($platform) {
            'toutiao' => $this->redirectToutiao($wsId),
            default => redirect()->back()->withErrors("不支持的 OAuth 平台: {$platform}"),
        };
    }

    /**
     * GET /oauth/callback/{platform}
     * OAuth 回调：平台授权完成后跳回此地址，携带 auth_code。
     */
    public function callback(string $platform, Request $request): RedirectResponse
    {
        $authCode = trim((string) $request->query('auth_code', ''));
        $state = trim((string) $request->query('state', ''));

        if ($authCode === '') {
            return redirect()->route('client.login')->withErrors('OAuth 授权失败：未收到授权码');
        }

        // state 参数携带 workspace_id
        $wsId = 0;
        if ($state !== '') {
            $decoded = json_decode(base64_decode($state), true);
            $wsId = (int) ($decoded['ws'] ?? 0);
        }

        try {
            return match ($platform) {
                'toutiao' => $this->callbackToutiao($authCode, $wsId),
                default => redirect()->route('client.login')->withErrors("不支持的 OAuth 平台: {$platform}"),
            };
        } catch (\Throwable $e) {
            Log::error("OAuth callback failed: {$platform}", ['error' => $e->getMessage()]);
            return redirect()->route('client.login')->withErrors("OAuth 授权失败：{$e->getMessage()}");
        }
    }

    // ── 头条 OAuth ────────────────────────────────────

    private function redirectToutiao(int $wsId): RedirectResponse
    {
        // 确保 ContentPublisherAccount 存在（避免客户端点击时因缺少记录而报错）
        $sync = app(PlatformSyncService::class);
        $sync->syncBinding($wsId, ['platform_key' => 'toutiao', 'platform_name' => '今日头条', 'source' => 'oauth_redirect']);

        $account = ContentPublisherAccount::query()
            ->where('workspace_id', $wsId)
            ->where('platform_key', 'toutiao')
            ->first();

        // 读取 OAuth 凭证：优先 workspace 级，fallback 系统全局配置
        $clientKey = $account?->oauth_app_id ?: (string) env('TOUTIAO_CLIENT_KEY', '');
        $clientSecret = ($account?->oauth_extra['client_secret'] ?? '') ?: (string) env('TOUTIAO_CLIENT_SECRET', '');

        if ($clientKey === '' || $clientSecret === '') {
            return redirect()->back()->withErrors('头条 OAuth 未配置：请在 .env 中设置 TOUTIAO_CLIENT_KEY 和 TOUTIAO_CLIENT_SECRET');
        }

        // 持久化到 workspace 级账号
        if ($account && (empty($account->oauth_app_id) || empty($account->oauth_extra['client_secret'] ?? ''))) {
            $account->forceFill([
                'oauth_app_id' => $clientKey,
                'oauth_extra' => array_merge($account->oauth_extra ?? [], ['client_secret' => $clientSecret]),
            ])->save();
        }

        $redirectUri = $this->redirectUri('toutiao');
        $state = base64_encode(json_encode(['ws' => $wsId]));

        $url = 'https://open.snssdk.com/open/authorize/?' . http_build_query([
            'client_key' => $clientKey,
            'response_type' => 'code',
            'scope' => 'article.content.create,article.content.read,user.info',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return redirect()->away($url);
    }

    private function callbackToutiao(string $authCode, int $wsId): RedirectResponse
    {
        // 获取 client_secret
        $account = ContentPublisherAccount::query()
            ->where('workspace_id', $wsId)
            ->where('platform_key', 'toutiao')
            ->first();

        $oauthExtra = $account?->oauth_extra ?? [];
        $clientKey = $account?->oauth_app_id ?? '';
        $clientSecret = $oauthExtra['client_secret'] ?? '';

        if ($clientKey === '' || $clientSecret === '') {
            return redirect()->route('client.login')->withErrors('头条 OAuth 配置不完整：缺少 client_key 或 client_secret');
        }

        // 用 auth_code 换取 access_token
        $resp = Http::timeout(15)->post('https://open.snssdk.com/open/api/v2/oauth/access_token/', [
            'client_key' => $clientKey,
            'client_secret' => $clientSecret,
            'code' => $authCode,
            'grant_type' => 'authorization_code',
        ]);

        $data = $resp->json('data') ?? [];

        if (empty($data['access_token'])) {
            $err = $data['description'] ?? '未知错误';
            Log::error('Toutiao OAuth token exchange failed', ['response' => $resp->json()]);
            return redirect()->route('client.login')->withErrors("头条 OAuth 授权失败：{$err}");
        }

        // 存储 token
        $account->forceFill([
            'credential_metadata' => [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? '',
                'expires_at' => time() + ($data['expires_in'] ?? 7200),
                'open_id' => $data['open_id'] ?? '',
            ],
            'status' => 'active',
            'last_health_checked_at' => now(),
            'last_health_status' => 'healthy',
        ])->save();

        // 同步到 ClientPlatformAccount（客户端可见）
        app(PlatformSyncService::class)->syncBinding($wsId, [
            'platform_key' => 'toutiao',
            'platform_name' => '今日头条',
            'source' => 'oauth_callback',
        ]);

        Log::info('Toutiao OAuth authorized', ['workspace_id' => $wsId]);

        return redirect()->route('client.platforms')->with('message', '✅ 今日头条 OAuth 授权成功！Token 已自动安全存储。');
    }

    // ── 工具方法 ──────────────────────────────────────

    private function redirectUri(string $platform): string
    {
        $base = rtrim((string) config('app.url'), '/');
        if ($base === '' || $base === 'http://localhost') {
            $base = 'http://127.0.0.1:18080';
        }
        return "{$base}/oauth/callback/{$platform}";
    }
}
