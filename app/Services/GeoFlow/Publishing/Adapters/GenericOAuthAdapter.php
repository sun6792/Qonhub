<?php

namespace App\Services\GeoFlow\Publishing\Adapters;

use App\Models\Article;
use App\Services\GeoFlow\Publishing\BasePlatformAdapter;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 通用 OAuth 适配器（适用于头条/百家/网易等 OAuth 2.0 标准平台）。
 *
 * 子类只需覆盖 platformKey() 和 apiBase() 即可适配不同平台。
 */
class GenericOAuthAdapter extends BasePlatformAdapter
{
    public function platformKey(): string
    {
        return $this->account->platform_key;
    }

    public function checkHealth(): array
    {
        try {
            $token = $this->getAccessToken();

            return ['healthy' => true, 'message' => 'ok'];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'message' => $e->getMessage()];
        }
    }

    protected function doPublish(Article $article, array $adaptedContent): array
    {
        $token = $this->getAccessToken();
        $apiBase = $this->apiBase();

        $resp = Http::timeout(30)
            ->withToken($token)
            ->post("{$apiBase}/article/publish", [
                'title' => $adaptedContent['title'],
                'content' => $adaptedContent['body'],
                'abstract' => $adaptedContent['excerpt'] ?? '',
                'keywords' => $adaptedContent['keywords'] ?? '',
                'cover_images' => array_slice($adaptedContent['images'] ?? [], 0, 3),
            ]);

        $data = $resp->json() ?? [];

        if ($resp->successful() && ($data['code'] ?? 1) === 0) {
            return [
                'success' => true,
                'remote_id' => (string) ($data['data']['article_id'] ?? ''),
                'remote_url' => (string) ($data['data']['url'] ?? ''),
                'remote_status' => 'published',
                'raw_response' => $data,
            ];
        }

        return [
            'success' => false,
            'remote_id' => '',
            'remote_url' => '',
            'remote_status' => 'error',
            'raw_response' => array_merge($data, ['error' => $data['message'] ?? '发布失败']),
        ];
    }

    // ── 子类可覆盖 ──────────────────────────────────────

    protected function apiBase(): string
    {
        return 'https://open.api.platform.com';
    }

    // ── Token 管理 ───────────────────────────────────────

    protected function getAccessToken(): string
    {
        $meta = $this->account->credential_metadata ?? [];

        if (! empty($meta['access_token']) && ($meta['expires_at'] ?? 0) > time() + 300) {
            return $meta['access_token'];
        }

        throw new RuntimeException('OAuth token 已过期，请重新授权');
    }
}
