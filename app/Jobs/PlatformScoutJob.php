<?php

namespace App\Jobs;

use App\Models\AiVisibilityCheck;
use App\Services\Agent\Tools\PlaywrightMcpTool;
use App\Services\AI\ChatRequest;
use App\Services\AI\LlmOrchestratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * v2.6.0 Phase 1: 单平台 Scout 检测 Job。
 *
 * 每个 Job 负责在 1 个 AI 平台上搜索 1 个品牌+关键词组合。
 * 由 AiVisibilityService 批量分发到 agent_scout 队列实现并行异步检测。
 *
 * 检测策略：
 *   - 有 API 的平台 → 直接调用其真实 API（DeepSeek/通义千问/Kimi/文心/智谱）
 *   - 无 API 的平台 → Playwright MCP 浏览器抓取
 */
class PlatformScoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 180;

    /** @var list<string> 有真实可调用 API 的平台 */
    private const API_PLATFORMS = ['deepseek', 'qianwen', 'kimi', 'wenxin', 'zhipu'];

    public function __construct(
        private readonly int    $workspaceId,
        private readonly string $platformKey,
        private readonly string $keyword,
        private readonly string $brandName,
    ) {}

    public function handle(
        LlmOrchestratorService $llm,
        ?PlaywrightMcpTool $mcp = null,
    ): void {
        $startTime = microtime(true);

        try {
            if (in_array($this->platformKey, self::API_PLATFORMS, true)) {
                $result = $this->searchViaApi($llm);
            } else {
                $result = $this->searchViaBrowser($mcp);
            }

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // v2.6.0: 引用匹配——检查AI回复中是否提到了本workspace已发布的文章
            $citedIds = [];
            if ($result['mentioned']) {
                $snippet = $result['snippet'] ?? '';
                $articles = \App\Models\Article::whereIn('id', function ($sub) {
                    $sub->select('assignable_id')->from('workspace_assignments')
                        ->where('assignable_type', \App\Models\Article::class)
                        ->where('workspace_id', $this->workspaceId);
                })->where('status', 'published')->get(['id', 'title', 'keywords']);
                foreach ($articles as $art) {
                    $title = mb_strtolower($art->title ?? '');
                    $kws = mb_strtolower($art->keywords ?? '');
                    if (($title && str_contains(mb_strtolower($snippet), mb_substr($title, 0, 6)))
                        || ($kws && str_contains(mb_strtolower($snippet), mb_substr($kws, 0, 4)))) {
                        $citedIds[] = (int) $art->id;
                    }
                }
            }

            AiVisibilityCheck::query()->create([
                'workspace_id' => $this->workspaceId,
                'ai_platform' => $this->platformKey,
                'query_keyword' => $this->keyword,
                'query_text' => $result['query_text'],
                'mentioned' => $result['mentioned'],
                'mention_type' => $result['mentioned'] ? ($result['is_positive'] ? 'positive' : 'neutral') : null,
                'response_snippet' => $result['snippet'],
                'cited_article_ids' => $citedIds ?: null,
                'cited_article_count' => count($citedIds),
                'raw_response_meta' => $result['raw_meta'] ?? [],
                'duration_ms' => $durationMs,
                'checked_at' => now(),
            ]);

            Log::info('PlatformScout completed', [
                'workspace_id' => $this->workspaceId,
                'platform' => $this->platformKey,
                'keyword' => $this->keyword,
                'mentioned' => $result['mentioned'],
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable $e) {
            // 记录失败但不阻塞其他平台的检测
            AiVisibilityCheck::query()->create([
                'workspace_id' => $this->workspaceId,
                'ai_platform' => $this->platformKey,
                'query_keyword' => $this->keyword,
                'query_text' => "搜索{$this->brandName} {$this->keyword}",
                'mentioned' => false,
                'mention_type' => null,
                'response_snippet' => null,
                'raw_response_meta' => ['error' => $e->getMessage()],
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'checked_at' => now(),
            ]);

            Log::warning('PlatformScout failed', [
                'workspace_id' => $this->workspaceId,
                'platform' => $this->platformKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 通过真实 API 搜索（DeepSeek/通义千问/Kimi/文心/智谱）。
     */
    /**
     * API 轨：直接 PHP curl 调用（绕过 Laravel Http/Guzzle 层网络问题）。
     * API Key 从数据库 AiModel 记录的加密字段解密获取。
     */
    private function searchViaApi(LlmOrchestratorService $llm): array
    {
        $apiKey = $this->resolveApiKey();
        if ($apiKey === null) {
            return ['query_text' => "{$this->brandName} {$this->keyword}", 'mentioned' => false, 'is_positive' => false, 'snippet' => null, 'raw_meta' => ['method' => 'api', 'error' => 'no_api_key']];
        }

        $prompt = "请如实回答：你是否了解「{$this->brandName}」这个品牌在「{$this->keyword}」方面的业务？"
            . "如果了解请简要描述，如果不了解请直接说'不知道'。";

        try {
            $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => 'deepseek-v4-flash',
                    'messages' => [
                        ['role' => 'system', 'content' => '你是品牌监测助手。如实回答是否知道这个品牌及其业务。不知道就说不知道。'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'max_tokens' => 128,
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code !== 200) {
                return ['query_text' => "{$this->brandName} {$this->keyword}", 'mentioned' => false, 'is_positive' => false, 'snippet' => null, 'raw_meta' => ['method' => 'api_direct', 'http_code' => $code]];
            }

            $data = json_decode($resp, true);
            $text = $data['choices'][0]['message']['content'] ?? '';
            $mentioned = $this->analyzeMention($text);

            return [
                'query_text' => "{$this->brandName} {$this->keyword}",
                'mentioned' => $mentioned, 'is_positive' => $mentioned,
                'snippet' => mb_substr($text, 0, 300),
                'raw_meta' => ['method' => 'api_direct', 'tokens' => $data['usage']['total_tokens'] ?? 0],
            ];
        } catch (\Throwable $e) {
            return ['query_text' => "{$this->brandName} {$this->keyword}", 'mentioned' => false, 'is_positive' => false, 'snippet' => null, 'raw_meta' => ['method' => 'api_direct', 'error' => $e->getMessage()]];
        }
    }

    /** 从数据库解密 DeepSeek API Key */
    private function resolveApiKey(): ?string
    {
        try {
            $model = \App\Models\AiModel::query()->where('status', 'active')->first();
            if (! $model) return null;
            $crypto = app(\App\Support\GeoFlow\ApiKeyCrypto::class);
            return $crypto->decrypt((string) $model->getRawOriginal('api_key'));
        } catch (\Throwable) { return null; }
    }

    /**
     * 通过 Playwright MCP 浏览器搜索（豆包/元宝/百度AI/微信AI/抖音AI/夸克AI/讯飞星火/纳米AI）。
     */
    private function searchViaBrowser(?PlaywrightMcpTool $mcp): array
    {
        $platformUrls = [
            'doubao' => 'https://www.doubao.com/',
            'yuanbao' => 'https://yuanbao.tencent.com/',
            'baidu_ai' => 'https://chat.baidu.com/search',
            'xf_xinghuo' => 'https://xinghuo.xfyun.cn/',
            'nami_ai' => 'https://www.nami.com/',
            'wechat_ai' => 'https://weixin.qq.com/',
            'douyin_ai' => 'https://www.douyin.com/',
            'quark_ai' => 'https://ai.quark.cn/',
        ];

        $targetUrl = $platformUrls[$this->platformKey] ?? null;
        if (! $targetUrl || ! $mcp) {
            return [
                'query_text' => "浏览器搜索: {$this->brandName} {$this->keyword}",
                'mentioned' => false,
                'is_positive' => false,
                'snippet' => null,
                'raw_meta' => ['method' => 'browser', 'status' => 'skipped'],
            ];
        }

        try {
            $query = "{$this->brandName} {$this->keyword}";

            // ① 导航到平台
            $navResult = $mcp->execute(['action' => 'navigate', 'url' => $targetUrl, 'platform_key' => $this->platformKey], $this->workspaceId);
            if (! ($navResult['success'] ?? false)) {
                return $this->browserResult($query, 'navigation_failed');
            }

            // ② 查找输入框并输入搜索词
            $searchSelectors = ['textarea', 'input[type="text"]', 'input[type="search"]', '[contenteditable="true"]', '.chat-input', '#search-input'];
            $typed = false;
            foreach ($searchSelectors as $sel) {
                $typeResult = $mcp->execute(['action' => 'type', 'selector' => $sel, 'text' => $query, 'platform_key' => $this->platformKey], $this->workspaceId);
                if ($typeResult['success'] ?? false) { $typed = true; break; }
            }

            // ③ 提交搜索（按Enter或点击发送按钮）
            if ($typed) {
                $mcp->execute(['action' => 'click', 'selector' => 'button[type="submit"], .send-btn, .submit-btn, button:has-text("发送"), button:has-text("搜索")', 'platform_key' => $this->platformKey], $this->workspaceId);
                // 等待响应
                sleep(3);
            }

            // ④ 获取搜索结果
            $snapshot = $mcp->execute(['action' => 'snapshot', 'platform_key' => $this->platformKey], $this->workspaceId);
            $pageText = $snapshot['data']['content'][0]['text'] ?? '';

            // ⑤ 截图保存为证据
            $mcp->execute(['action' => 'screenshot', 'platform_key' => $this->platformKey], $this->workspaceId);

            $mentioned = $this->analyzeMention($pageText);

            return [
                'query_text' => $query,
                'mentioned' => $mentioned,
                'is_positive' => $mentioned,
                'snippet' => mb_substr($pageText, 0, 300),
                'raw_meta' => ['method' => 'browser', 'url' => $targetUrl, 'typed' => $typed, 'page_has_brand' => $mentioned],
            ];
        } catch (\Throwable $e) {
            return [
                'query_text' => "浏览器搜索: {$this->brandName} {$this->keyword}",
                'mentioned' => false,
                'is_positive' => false,
                'snippet' => null,
                'raw_meta' => ['method' => 'browser', 'error' => $e->getMessage()],
            ];
        }
    }

    /**
     * 分析响应文本是否包含品牌提及。
     */
    private function browserResult(string $query, string $status): array
    {
        return ['query_text' => $query, 'mentioned' => false, 'is_positive' => false, 'snippet' => null, 'raw_meta' => ['method' => 'browser', 'status' => $status]];
    }

    private function analyzeMention(string $text): bool
    {
        $text = mb_strtolower($text, 'UTF-8');
        $brand = mb_strtolower($this->brandName, 'UTF-8');
        if ($brand === '' || !str_contains($text, $brand) && !str_contains($text, mb_substr($brand, 0, min(4, mb_strlen($brand))))) {
            return false; // 品牌名根本没出现
        }

        // 品牌名出现了，但要看上下文：是正面提及还是AI说不认识
        // AI常见的"不知道"表达
        $dontKnow = [
            '不了解', '不知道', '不清楚', '无法确认', '没有信息', '没有相关', '没有找到',
            '没听说过', '没有记录', '并非.*认知', '没有明确', '没有公开', '没有足够',
            '无法提供', '不建议', '没有找到', '抱歉.*没有', '没有关于', '不能确认',
            'cannot', 'no information', 'not aware', 'no record', 'not familiar',
        ];
        foreach ($dontKnow as $pattern) {
            if (preg_match('/' . $pattern . '/u', $text)) {
                return false; // AI明确说不认识这个品牌
            }
        }

        // AI说了品牌名但没有否定 → 真正的提及
        return true;
    }
}
