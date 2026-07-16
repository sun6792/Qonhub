<?php

namespace App\Services\GeoFlow\Publishing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RPA 真实浏览器搜索客户端 — 调用 RPA 引擎 /api/v1/scout 端点。
 *
 * 用于 Scout Agent 在 AI 平台网页端执行真实搜索（非 API 调用），
 * 获取用户在网页端实际看到的结果。
 */
class RpaScoutClient
{
    /** @var list<string> 支持 RPA 搜索的平台 */
    public const RPA_PLATFORMS = ['doubao', 'yuanbao', 'baidu', 'deepseek', 'qianwen', 'xf_xinghuo', 'kimi'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 30,
    ) {}

    /**
     * 在指定 AI 平台执行真实浏览器搜索。
     *
     * @return array{success:bool, answer:string, cited_urls:list<string>, error?:string}
     */
    public function search(string $platform, string $query, int $workspaceId = 0): array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v1/scout';

        try {
            $response = Http::withHeaders([
                    'X-Api-Key' => $this->apiKey,
                    'Content-Type' => 'application/json; charset=utf-8',
                ])
                ->timeout($this->timeout + 15)
                ->post($url, [
                    'platform' => $platform,
                    'query' => $query,
                    'workspace_id' => $workspaceId,
                ]);

            if (! $response->successful()) {
                Log::warning("RpaScout: HTTP {$response->status()} for {$platform}");
                return ['success' => false, 'answer' => '', 'cited_urls' => [], 'error' => "HTTP {$response->status()}"];
            }

            $data = $response->json();
            return [
                'success' => (bool) ($data['success'] ?? false),
                'answer' => (string) ($data['answer'] ?? ''),
                'cited_urls' => (array) ($data['cited_urls'] ?? []),
                'error' => $data['error'] ?? ($data['success'] ? null : 'unknown_error'),
            ];
        } catch (\Throwable $e) {
            Log::warning("RpaScout: {$platform} failed — {$e->getMessage()}");
            return ['success' => false, 'answer' => '', 'cited_urls' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * 判断指定平台是否支持 RPA 搜索（需要浏览器登录态）。
     */
    public static function supportsRpa(string $platform): bool
    {
        return in_array($platform, self::RPA_PLATFORMS, true);
    }
}
