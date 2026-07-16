<?php

namespace App\Services\Agent;

use App\Models\AgentExecution;
use App\Models\Workspace;
use App\Services\AI\ChatRequest;
use App\Services\AI\LlmOrchestratorService;
use App\Services\GeoFlow\AiVisibilityService;
use App\Services\GeoFlow\EnterpriseAnchorService;
use App\Services\GeoFlow\Publishing\RpaScoutClient;

/**
 * 侦察 Agent — 混合模式：RPA 真实浏览器搜索 + API 对话。
 *
 * RPA 平台（豆包/元宝/百度AI）：Playwright 打开网页端搜索品牌词，模拟用户行为。
 * API 平台（DeepSeek/通义/讯飞/硅基）：调用 LLM API 直接对话模型。
 */
class ScoutAgentService
{
    public function __construct(
        private readonly AiVisibilityService $visibilityService,
        private readonly EnterpriseAnchorService $anchorService,
    ) {}

    /**
     * @return array{ brand_mentions: array, anchor_status: array, gaps: array, checked_at: string }
     */
    public function execute(AgentExecution $execution): array
    {
        $wsId = (int) $execution->workspace_id;
        $workspace = Workspace::query()->find($wsId);
        if (! $workspace) {
            throw new \RuntimeException("工作空间不存在: {$wsId}");
        }

        // ① AI 品牌可见度检测（DB历史数据）
        $visibilityData = $this->visibilityService->clientVisibilityData($wsId);

        // ② B2B 锚点巡检
        $profile = $this->anchorService->getOrInitProfile($workspace);
        $summary = $this->anchorService->certificationSummary($profile);

        // ③ 收录缺口分析
        $gaps = [];
        foreach (($summary['platforms'] ?? collect()) as $platform) {
            $status = $platform['certification_status'] ?? 'pending';
            if ($status !== 'certified') {
                $gaps[] = [
                    'platform_key' => $platform['anchor_platform_key'] ?? '',
                    'platform_name' => $platform['platform_name'] ?? '',
                    'status' => $status,
                    'action_needed' => $status === 'pending' ? 'register' : 'recertify',
                ];
            }
        }

        // ④ 实时 AI 品牌检测（RPA 浏览器 + API 对话，workspace 开关控制）
        $liveSnapshots = [];
        if ($workspace->config['auto_scout_live'] ?? true) {
            $brandName = $workspace->client_company_name ?: $workspace->name;
            $liveSnapshots = $this->executeLiveBrandScout($wsId, $brandName, (int) $execution->id);
        }

        return [
            'brand_mentions' => [
                'mentioned_platforms' => $visibilityData['mentioned_platforms'] ?? [],
                'total_checks_today' => $visibilityData['total_checks_today'] ?? 0,
                'mention_rate' => $visibilityData['mention_rate'] ?? 0,
            ],
            'anchor_status' => [
                'has_profile' => $profile->exists
                    && ! empty($profile->company_full_name)
                    && (! empty($profile->company_phone) || ! empty($profile->business_scope)),
                'total_platforms' => $summary['total'] ?? 0,
                'certified' => $summary['certified'] ?? 0,
                'pending' => $summary['pending'] ?? 0,
                'expired' => $summary['expired'] ?? 0,
            ],
            'gaps' => $gaps,
            'live_snapshots' => $liveSnapshots,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * 实时 AI 品牌搜索 — 混合模式。
     *
     * RPA：豆包/元宝/百度AI 网页端真实搜索（仅当 RPA 引擎可连接时启用）。
     * API：其余平台调用 LLM API 对话。
     *
     * @return list<array{provider:string, name:string, model:string, mentioned:bool, score:int, preview:string}>
     */
    public function executeLiveBrandScout(int $workspaceId, string $brandName, int $agentExecutionId = 0): array
    {
        $results = [];

        // ── RPA 真实浏览器搜索 ──
        $rpaAvailable = $this->isRpaAvailable();
        if ($rpaAvailable) {
            $rpaPlatforms = ['yuanbao', 'baidu'];  // 仅无 API 的平台走 RPA，其余走 API 避免验证码
            $results = array_merge($results, $this->executeRpaScout($rpaPlatforms, $brandName, $workspaceId));
        }

        // ── API 对话 ──
        $apiPlatforms = $this->resolveAvailableScoutPlatforms();
        $results = array_merge($results, $this->executeApiScout($apiPlatforms, $brandName, $workspaceId, $agentExecutionId));

        return $results;
    }

    /** 检测 RPA 引擎是否在线 */
    private function isRpaAvailable(): bool
    {
        try {
            $url = rtrim((string) config('geoflow.rpa_engine_url', 'http://127.0.0.1:9901'), '/') . '/api/v1/health';
            $response = \Illuminate\Support\Facades\Http::timeout(3)->get($url);
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** RPA 浏览器搜索 */
    private function executeRpaScout(array $platforms, string $brandName, int $workspaceId): array
    {
        $results = [];
        $rpaUrl = rtrim((string) config('geoflow.rpa_engine_url', 'http://127.0.0.1:9901'), '/');
        $rpaKey = (string) config('geoflow.rpa_engine_api_key');
        $client = new RpaScoutClient($rpaUrl, $rpaKey);
        $names = ['yuanbao' => '元宝(RPA)', 'baidu' => '百度AI(RPA)'];

        foreach ($platforms as $p) {
            try {
                $result = $client->search($p, "{$brandName}是什么品牌", $workspaceId);
                $text = $result['answer'] ?? '';
                $mentioned = $result['success'] && $text !== '' && mb_stripos($text, $brandName) !== false;
                $score = $mentioned ? $this->computeMentionScore($text, $brandName) : 0;

                if ($result['success'] && $text !== '') {
                    $this->saveRpaSnapshot($workspaceId, $p, $brandName, $text, $result['cited_urls'] ?? [], $mentioned, $score);
                }

                $results[] = [
                    'provider' => $p, 'name' => $names[$p] ?? $p, 'model' => 'rpa-search',
                    'mentioned' => $mentioned, 'score' => $score, 'preview' => mb_substr($text, 0, 200),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'provider' => $p, 'name' => $names[$p] ?? $p, 'model' => 'rpa-search',
                    'mentioned' => false, 'score' => 0, 'preview' => '',
                    'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    private function saveRpaSnapshot(int $wsId, string $platform, string $brandName, string $text, array $urls, bool $mentioned, int $score): void
    {
        try {
            \App\Models\AgentConversationSnapshot::create([
                'workspace_id' => $wsId, 'ai_provider_code' => $platform,
                'model_id' => 'rpa-search', 'prompt' => "RPA搜索: {$brandName}",
                'response_text' => mb_substr($text, 0, 10000), 'cited_urls' => $urls,
                'brand_mentioned' => $mentioned, 'brand_name' => $brandName,
                'geo_score' => $score, 'snapshot_at' => now(),
            ]);
        } catch (\Throwable) {}
    }

    /** API 对话搜索 */
    private function executeApiScout(array $platforms, string $brandName, int $workspaceId, int $agentExecutionId): array
    {
        $results = [];
        $orchestrator = app(LlmOrchestratorService::class);

        foreach ($platforms as $platform) {
            try {
                $ws = $platform['web_search'] ?? false;

                // DeepSeek 联网搜索：走 Anthropic 端点（非 OpenAI 兼容）
                if ($ws === 'deepseek_anthropic') {
                    $result = $this->deepseekAnthropicSearch($brandName, $workspaceId, $agentExecutionId);
                    $results[] = $result;
                    continue;
                }

                // 联网搜索参数（各平台格式不同）
                $extraOptions = ['max_tokens' => 1024, 'temperature' => 0.3];
                if ($ws === true || $ws === 'search') {
                    $extraOptions['tools'] = [['type' => 'search']];           // 豆包
                } elseif ($ws === 'enable_search') {
                    $extraOptions['enable_search'] = true;                      // 通义千问
                } elseif ($ws === 'tools') {
                    $extraOptions['tools'] = [['type' => 'web_search', 'web_search' => ['enable' => true]]]; // 讯飞星火
                }

                $response = $orchestrator->chat(new ChatRequest(
                    providerCode: $platform['provider'],
                    modelId: $platform['model'],
                    messages: [
                        ['role' => 'system', 'content' => '你是一个诚实的AI助手。请如实回答你是否知道以下品牌/产品，知道就详细描述，不知道就说不知道。'],
                        ['role' => 'user', 'content' => "请问你是否知道「{$brandName}」这个品牌/产品？如果知道，请详细描述它的业务和特点。"],
                    ],
                    options: $extraOptions,
                    workspaceId: $workspaceId,
                    agentExecutionId: $agentExecutionId,
                ));

                $text = $response->text ?? '';
                $mentioned = stripos($text, $brandName) !== false;
                $score = $mentioned ? $this->computeMentionScore($text, $brandName) : 0;
                $this->backfillSnapshotAnalysis($response, $brandName, $mentioned, $score);

                $results[] = [
                    'provider' => $platform['provider'], 'name' => $platform['name'],
                    'model' => $platform['model'], 'mentioned' => $mentioned,
                    'score' => $score, 'preview' => mb_substr($text, 0, 200),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'provider' => $platform['provider'], 'name' => $platform['name'],
                    'model' => $platform['model'], 'mentioned' => false,
                    'score' => 0, 'preview' => '', 'error' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    /** DeepSeek 联网搜索 — 走 Anthropic 端点（非 OpenAI 兼容） */
    private function deepseekAnthropicSearch(string $brandName, int $workspaceId, int $agentExecutionId): array
    {
        $model = \App\Models\AiModel::where('model_id', 'deepseek-v4-flash')->where('status', 'active')->first();
        if (! $model) {
            return ['provider' => 'deepseek', 'name' => 'DeepSeek', 'model' => 'deepseek-v4-flash',
                'mentioned' => false, 'score' => 0, 'preview' => '', 'error' => 'Model not configured'];
        }

        $crypto = app(\App\Support\GeoFlow\ApiKeyCrypto::class);
        $apiKey = $crypto->decrypt((string) $model->getRawOriginal('api_key'));

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Content-Type' => 'application/json',
            'x-api-key' => $apiKey,
        ])->timeout(30)->post('https://api.deepseek.com/anthropic/v1/messages', [
            'model' => 'deepseek-v4-flash',
            'max_tokens' => 1024,
            'messages' => [
                ['role' => 'user', 'content' => "请联网搜索：{$brandName}是什么品牌？请详细描述它的业务和特点。"],
            ],
            'tools' => [
                ['type' => 'web_search_20260209', 'name' => 'web_search'],
            ],
        ]);

        if (! $response->successful()) {
            return ['provider' => 'deepseek', 'name' => 'DeepSeek', 'model' => 'deepseek-v4-flash',
                'mentioned' => false, 'score' => 0, 'preview' => '',
                'error' => "HTTP {$response->status()}: " . mb_substr($response->body(), 0, 100)];
        }

        $data = $response->json();
        $text = '';
        // Anthropic 格式：提取 content 块中的文本
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        $mentioned = $text !== '' && mb_stripos($text, $brandName) !== false;
        $score = $mentioned ? $this->computeMentionScore($text, $brandName) : 0;

        // 保存快照
        try {
            \App\Models\AgentConversationSnapshot::create([
                'workspace_id' => $workspaceId, 'agent_execution_id' => $agentExecutionId,
                'ai_provider_code' => 'deepseek', 'model_id' => 'deepseek-v4-flash',
                'prompt' => "DeepSeek联网搜索: {$brandName}",
                'response_text' => mb_substr($text, 0, 10000),
                'brand_mentioned' => $mentioned, 'brand_name' => $brandName, 'geo_score' => $score,
                'snapshot_at' => now(),
            ]);
        } catch (\Throwable) {}

        return ['provider' => 'deepseek', 'name' => 'DeepSeek', 'model' => 'deepseek-v4-flash',
            'mentioned' => $mentioned, 'score' => $score, 'preview' => mb_substr($text, 0, 200)];
    }

    private function computeMentionScore(string $text, string $brandName): int
    {
        $count = mb_substr_count(mb_strtolower($text), mb_strtolower($brandName));
        if ($count === 0) return 0;
        $base = min($count * 20, 80);
        if (mb_stripos(mb_substr($text, 0, 100), $brandName) !== false) $base += 10;
        $positiveHints = ['专业', '领先', '知名', '优秀', '推荐', '权威', '专注', '创新'];
        foreach ($positiveHints as $hint) {
            if (mb_stripos($text, $hint) !== false) { $base += 5; break; }
        }
        return min($base, 100);
    }

    private function backfillSnapshotAnalysis(
        \App\Services\AI\ChatResponse $response,
        string $brandName, bool $mentioned, int $score
    ): void {
        try {
            $query = \App\Models\AgentConversationSnapshot::query()
                ->where('ai_provider_code', $response->providerCode)
                ->where('model_id', $response->modelId)
                ->whereNull('brand_name')
                ->latest('snapshot_at')->limit(1);
            if ($response->workspaceId !== null) $query->where('workspace_id', $response->workspaceId);
            $query->update(['brand_mentioned' => $mentioned, 'brand_name' => $brandName, 'geo_score' => $score]);
        } catch (\Throwable) {}
    }

    private function resolveAvailableScoutPlatforms(): array
    {
        $candidates = [
            ['provider' => 'deepseek',    'model' => 'deepseek-v4-flash',               'name' => 'DeepSeek', 'web_search' => 'deepseek_anthropic'],
            ['provider' => 'doubao',      'model' => 'doubao-seed-2-1-turbo-260628',    'name' => '豆包', 'web_search' => true],
            ['provider' => 'qwen',        'model' => 'qwen-plus',                       'name' => '通义千问', 'web_search' => 'enable_search'],
            ['provider' => 'xf_xinghuo',  'model' => 'spark-x',                         'name' => '讯飞星火', 'web_search' => 'tools'],
            ['provider' => 'siliconflow', 'model' => 'Qwen/Qwen2.5-7B-Instruct',        'name' => '硅基千问(免费)'],
        ];

        $crypto = app(\App\Support\GeoFlow\ApiKeyCrypto::class);
        $available = [];
        foreach ($candidates as $p) {
            $model = \App\Models\AiModel::where('model_id', $p['model'])->where('status', 'active')->first();
            if (! $model) continue;
            $raw = (string) $model->getRawOriginal('api_key');
            if ($raw === '' || strlen($raw) < 10) continue;
            try { if (strlen($crypto->decrypt($raw)) < 20) continue; } catch (\Throwable) { continue; }
            $available[] = $p;
        }
        if ($available === []) {
            $available[] = ['provider' => 'deepseek', 'model' => 'deepseek-v4-flash', 'name' => 'DeepSeek'];
        }
        return $available;
    }

    public function executeATypeCompetitorAnalysis(int $workspaceId, string $brandName, string $competitorContent): array
    {
        try {
            $response = app(LlmOrchestratorService::class)->chat(new ChatRequest(
                providerCode: 'deepseek', modelId: 'deepseek-v4-flash',
                messages: [
                    ['role' => 'system', 'content' => '你是竞争情报分析专家。'],
                    ['role' => 'user', 'content' => "我方品牌：{$brandName}\n\n竞品素材：\n{$competitorContent}"],
                ],
                options: ['max_tokens' => 512], workspaceId: $workspaceId,
            ));
            return ['success' => true, 'insights' => $response->text];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
