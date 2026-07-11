<?php

namespace App\Services\GeoFlow\Publishing\Adapters;

use App\Models\Article;
use App\Services\GeoFlow\Publishing\BasePlatformAdapter;
use Illuminate\Support\Facades\Http;

/**
 * 媒介盒子 API 适配器（新闻媒体软文发稿）。
 *
 * 对接国内主流软文聚合平台的发稿 API，实现一键下单发稿。
 * 示例以"媒介盒子"为参考，实际可对接多个上游平台。
 *
 * 流程：提交订单 → 编辑审核 → 出稿 → 回传链接
 */
class MediaBoxApiAdapter extends BasePlatformAdapter
{
    protected string $apiBase = 'https://api.meijiehezi.com/v2';

    public function platformKey(): string
    {
        return 'media_box_api';
    }

    protected function doPublish(Article $article, array $adaptedContent): array
    {
        $apiKey = $this->getApiKey();

        // 1. 提交发稿订单
        $orderResp = Http::timeout(30)
            ->withHeader('X-API-Key', $apiKey)
            ->post("{$this->apiBase}/orders", [
                'title' => $adaptedContent['title'],
                'content' => $adaptedContent['body'],
                'media_ids' => $this->getMediaIds(),
                'publish_date' => now()->toDateString(),
                'callback_url' => route('api.v1.publish-callback', [], true),
            ]);

        if (! $orderResp->successful()) {
            return $this->fail('下单失败', $orderResp->json());
        }

        $orderData = $orderResp->json('data') ?? [];
        $orderId = $orderData['order_id'] ?? '';

        // 2. 轮询等待出稿（异步模式，这里只提交，实际回调处理）
        // 在正式实现中，应该通过 webhook 回调来处理出稿结果
        $status = $orderData['status'] ?? 'pending';

        if ($status === 'published') {
            return [
                'success' => true,
                'remote_id' => (string) $orderId,
                'remote_url' => $orderData['article_url'] ?? '',
                'remote_status' => 'published',
                'raw_response' => $orderData,
            ];
        }

        // 审核中 — 标记为审核中状态，等回调更新
        return [
            'success' => true,
            'remote_id' => (string) $orderId,
            'remote_url' => '',
            'remote_status' => 'reviewing',
            'raw_response' => $orderData,
        ];
    }

    protected function adaptFormat(array $content): array
    {
        // 媒体发稿正文需保留 Markdown 格式（各媒体格式要求不同）
        $content['title'] = mb_substr($content['title'], 0, 50);

        return $content;
    }

    // ── 辅助 ─────────────────────────────────────────────

    private function getApiKey(): string
    {
        $service = app(\App\Services\GeoFlow\Publishing\AccountPoolService::class);

        return $service->decryptCredential($this->account);
    }

    /**
     * 获取选定的媒体 ID 列表。
     */
    private function getMediaIds(): array
    {
        $meta = $this->account->credential_metadata ?? [];

        return $meta['media_ids'] ?? [];
    }

    private function fail(string $message, array $raw): array
    {
        return [
            'success' => false,
            'remote_id' => '',
            'remote_url' => '',
            'remote_status' => 'error',
            'raw_response' => array_merge($raw, ['error' => $message]),
        ];
    }
}
