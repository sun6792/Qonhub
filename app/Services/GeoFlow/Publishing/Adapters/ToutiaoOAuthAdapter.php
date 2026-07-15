<?php

namespace App\Services\GeoFlow\Publishing\Adapters;

use App\Models\Article;
use App\Services\GeoFlow\Publishing\BasePlatformAdapter;
use Illuminate\Support\Facades\Http;

/**
 * 头条号 OAuth 适配器。
 *
 * 对接头条开放平台（https://open.toutiao.com/）内容发布 API。
 * 支持 access_token 自动刷新、素材上传、图文发布。
 *
 * 流程：OAuth 授权 → 获取 token → 上传图片 → 创建草稿 → 发布 → 回传链接
 */
class ToutiaoOAuthAdapter extends BasePlatformAdapter
{
    protected string $apiBase = 'https://open.snssdk.com';

    public function platformKey(): string
    {
        return 'toutiao';
    }

    public function checkHealth(): array
    {
        try {
            $token = $this->getAccessToken();
            $resp = Http::timeout(10)
                ->get("{$this->apiBase}/open/api/v2/user/info/", [
                    'access_token' => $token,
                ]);

            if ($resp->successful() && ($resp->json('data.error_code') ?? 0) === 0) {
                return ['healthy' => true, 'message' => 'ok'];
            }

            return ['healthy' => false, 'message' => $resp->json('data.description') ?? 'token失效'];
        } catch (\Throwable $e) {
            return ['healthy' => false, 'message' => $e->getMessage()];
        }
    }

    protected function doPublish(Article $article, array $adaptedContent): array
    {
        $token = $this->getAccessToken();

        // 1. 上传图片素材
        $imageIds = [];
        foreach ($adaptedContent['images'] as $imageUrl) {
            $imageIds[] = $this->uploadImage($token, $imageUrl);
        }

        // 2. 创建图文草稿
        $draftResp = Http::timeout(30)
            ->withToken($token)
            ->post("{$this->apiBase}/open/api/v2/article/content/create/", [
                'title' => $adaptedContent['title'],
                'content' => $adaptedContent['body'],
                'cover_images' => array_slice($imageIds, 0, 3),
                'abstract' => $adaptedContent['excerpt'],
                'article_type' => 'article',
            ]);

        $draftData = $draftResp->json('data') ?? [];

        if (($draftData['error_code'] ?? -1) !== 0) {
            return $this->failResponse($draftData['description'] ?? '创建草稿失败', $draftData);
        }

        $articleId = $draftData['article_id'] ?? '';

        // 3. 发布
        $publishResp = Http::timeout(15)
            ->withToken($token)
            ->post("{$this->apiBase}/open/api/v2/article/content/publish/", [
                'article_id' => $articleId,
            ]);

        $publishData = $publishResp->json('data') ?? [];

        if (($publishData['error_code'] ?? -1) !== 0) {
            return $this->failResponse($publishData['description'] ?? '发布失败', $publishData);
        }

        return [
            'success' => true,
            'remote_id' => (string) $articleId,
            'remote_url' => $publishData['article_url'] ?? '',
            'remote_status' => 'published',
            'raw_response' => $publishData,
        ];
    }

    protected function adaptFormat(array $content): array
    {
        $content['title'] = mb_substr($content['title'], 0, 30); // 头条限制30字

        return $content;
    }

    // ── OAuth Token 管理 ─────────────────────────────────

    private function getAccessToken(): string
    {
        $meta = $this->account->credential_metadata ?? [];

        // Token 未过期直接返回
        if (! empty($meta['access_token']) && ($meta['expires_at'] ?? 0) > time() + 300) {
            return $meta['access_token'];
        }

        // 刷新 token
        $oauthExtra = $this->account->oauth_extra ?? [];

        $resp = Http::timeout(10)->post("{$this->apiBase}/open/api/v2/oauth/refresh_token/", [
            'client_key' => $this->account->oauth_app_id,
            'client_secret' => $oauthExtra['client_secret'] ?? '',
            'refresh_token' => $meta['refresh_token'] ?? '',
            'grant_type' => 'refresh_token',
        ]);

        $data = $resp->json('data') ?? [];

        if (empty($data['access_token'])) {
            throw new \RuntimeException('OAuth token 刷新失败: '.($data['description'] ?? '未知错误'));
        }

        // 更新加密存储
        $this->account->forceFill([
            'credential_metadata' => [
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? ($meta['refresh_token'] ?? ''),
                'expires_at' => time() + ($data['expires_in'] ?? 7200),
                'open_id' => $data['open_id'] ?? '',
            ],
        ])->save();

        return $data['access_token'];
    }

    private function uploadImage(string $token, string $imageUrl): string
    {
        $resp = Http::timeout(30)
            ->withToken($token)
            ->attach('image', file_get_contents($imageUrl), basename($imageUrl))
            ->post("{$this->apiBase}/open/api/v2/article/image/upload/");

        $data = $resp->json('data') ?? [];

        return (string) ($data['image_id'] ?? '');
    }

    // failResponse() 继承自 BasePlatformAdapter
}
