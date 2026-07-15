<?php

namespace App\Services\AI;

use App\Models\AiModel;
use App\Models\AiModelProvider;
use App\Services\AI\Adapters\BaseLlmAdapter;
use App\Support\GeoFlow\ApiKeyCrypto;
use RuntimeException;
use Throwable;

/**
 * 大模型统一调度服务 — Phase 1 核心。
 *
 * 提供：
 *   - chat()        纯文本对话
 *   - smartFailover() 故障自动切换
 *   - chatWithTools()  Function Calling (Phase 3)
 *
 * 使用方式：
 *   $response = app(LlmOrchestratorService::class)->chat(new ChatRequest(...));
 */
class LlmOrchestratorService
{
    public function __construct(
        private readonly LlmAdapterFactory $adapterFactory,
        private readonly TokenQuotaService $quotaService,
        private readonly ?ApiKeyCrypto $apiKeyCrypto = null,
    ) {}

    /**
     * 纯文本对话 — 单模型调用。
     */
    public function chat(ChatRequest $request): ChatResponse
    {
        // ① 额度检查
        if ($request->workspaceId > 0 && ! $this->quotaService->hasQuota($request->workspaceId, $request->providerCode)) {
            throw new RuntimeException("AI Token 额度已用尽 (workspace:{$request->workspaceId}, provider:{$request->providerCode})");
        }

        // ② 查找模型配置
        $aiModel = $this->resolveAiModel($request->providerCode, $request->modelId);

        // ③ 创建适配器 + 解密 API Key
        $adapter = $this->adapterFactory->createByCode(
            $request->providerCode,
            (string) ($aiModel->getRawOriginal('api_key') ?? ''),
            $aiModel->model_id
        );

        // ④ 调用 LLM API
        $result = $adapter->chat(
            $aiModel->model_id,
            $request->messages,
            $request->options
        );

        // ⑤ 扣减额度
        $tokensUsed = $result['tokens_used'] ?? 0;
        if ($request->workspaceId > 0 && $tokensUsed > 0) {
            $this->quotaService->deduct($request->workspaceId, $request->providerCode, $tokensUsed);
        }

        // ⑥ 更新用量统计
        $this->incrementModelUsage($aiModel);

        return new ChatResponse(
            text: $result['text'] ?? '',
            tokensUsed: $tokensUsed,
            modelId: $result['model'] ?? $aiModel->model_id,
            providerCode: $request->providerCode,
        );
    }

    /**
     * 智能故障切换 — 主模型失败时自动降级。
     *
     * 降级链：同 provider 其他模型 → 不同 provider → 兜底模型
     */
    public function smartFailover(
        string $primaryProviderCode,
        string $primaryModelId,
        array $messages,
        array $options = [],
        int $workspaceId = 0,
    ): ChatResponse {
        $circuitBreaker = new CircuitBreaker($workspaceId);
        $attempts = [];
        $lastException = null;

        // 构建候选模型列表
        $candidates = $this->buildFailoverCandidates($primaryProviderCode, $primaryModelId);

        foreach ($candidates as $candidate) {
            // 熔断检查
            if ($circuitBreaker->isOpen($candidate['provider_code'])) {
                $attempts[] = [
                    'provider' => $candidate['provider_code'],
                    'model' => $candidate['model_id'],
                    'status' => 'circuit_open',
                ];
                continue;
            }

            try {
                $response = $this->chat(new ChatRequest(
                    providerCode: $candidate['provider_code'],
                    modelId: $candidate['model_id'],
                    messages: $messages,
                    options: $options,
                    workspaceId: $workspaceId,
                ));

                $circuitBreaker->recordSuccess($candidate['provider_code']);

                return $response->withAttempts($attempts);

            } catch (Throwable $e) {
                $attempts[] = [
                    'provider' => $candidate['provider_code'],
                    'model' => $candidate['model_id'],
                    'status' => 'failed',
                    'reason' => $e->getMessage(),
                ];
                $circuitBreaker->recordFailure($candidate['provider_code']);
                $lastException = $e;

                // 指数退避
                usleep(min(100000 * pow(2, count($attempts)), 4000000));

                // 检查是否还有额度（前面的失败可能扣了部分）
                if ($workspaceId > 0 && ! $this->quotaService->hasQuota($workspaceId, $candidate['provider_code'])) {
                    $attempts[] = [
                        'provider' => $candidate['provider_code'],
                        'status' => 'quota_exceeded',
                    ];
                }
            }
        }

        throw new RuntimeException(
            '所有 AI 模型均调用失败。尝试列表: ' . json_encode($attempts, JSON_UNESCAPED_UNICODE),
            0,
            $lastException
        );
    }

    /**
     * 构建故障切换候选列表：主模型 → 同 provider 降级 → 不同 provider 降级
     */
    private function buildFailoverCandidates(string $primaryProviderCode, string $primaryModelId): array
    {
        $candidates = [];

        // ① 主模型排第一
        $candidates[] = ['provider_code' => $primaryProviderCode, 'model_id' => $primaryModelId];

        // ② 同 provider 其他 active 模型
        $sameProviderModels = AiModel::query()
            ->where('status', 'active')
            ->whereHas('provider', fn ($q) => $q->where('provider_code', $primaryProviderCode))
            ->where('model_id', '!=', $primaryModelId)
            ->orderBy('failover_priority')
            ->get();

        foreach ($sameProviderModels as $m) {
            $candidates[] = ['provider_code' => $primaryProviderCode, 'model_id' => $m->model_id];
        }

        // ③ 其他 active provider 的模型（按 failover_priority 排序）
        $otherModels = AiModel::query()
            ->where('status', 'active')
            ->whereHas('provider', fn ($q) => $q->where('is_active', true)
                ->where('provider_code', '!=', $primaryProviderCode)
                ->orderBy('failover_priority'))
            ->orderBy('failover_priority')
            ->get();

        $seenProviders = [$primaryProviderCode => true];
        foreach ($otherModels as $m) {
            $pc = $m->provider?->provider_code ?? 'unknown';
            if (! isset($seenProviders[$pc])) {
                $seenProviders[$pc] = true;
                $candidates[] = ['provider_code' => $pc, 'model_id' => $m->model_id];
            }
        }

        return $candidates;
    }

    /**
     * [Phase 3] 带工具调用的对话 — A 型 Agent 专用。
     *
     * LLM 自主决定调用哪些工具，PHP 层执行工具并返回结果，
     * 循环直到 LLM 返回纯文本或超过最大迭代次数。
     *
     * @param  \App\Services\Agent\AgentToolRegistry|null  $registry  工具注册中心
     * @param  string                                       $agentType Agent 类型（白名单过滤）
     * @param  string                                       $sessionId 会话 ID（限流）
     * @param  int                                          $maxIterations 最大工具调用轮数
     */
    public function chatWithTools(
        ChatRequest $request,
        ?\App\Services\Agent\AgentToolRegistry $registry = null,
        string $agentType = '',
        string $sessionId = '',
        int $maxIterations = 5,
    ): ChatResponse {
        $iteration = 0;
        $messages = $request->messages;
        $allToolCalls = [];

        // 构建 tools 数组（OpenAI Function Calling 格式）
        $tools = [];
        if ($registry) {
            $tools = $registry->toOpenAiFunctions($agentType);
        }
        // 合并请求中传入的额外 tools
        if ($request->tools !== []) {
            $tools = array_merge($tools, $request->tools);
        }

        while ($iteration < $maxIterations) {
            $iteration++;

            // ① 调用 LLM（带 tools 参数）
            $rawResponse = $this->callLlmRaw(
                $request->providerCode,
                $this->resolveAiModel($request->providerCode, $request->modelId),
                $messages,
                $request->options,
                $tools,
            );

            // ② 如果 LLM 返回纯文本 → 结束
            $toolCalls = $this->extractToolCalls($rawResponse);
            if ($toolCalls === []) {
                $text = $this->extractText($request->providerCode, $rawResponse);
                $tokensUsed = $rawResponse['usage']['total_tokens'] ?? 0;

                if ($request->workspaceId > 0 && $tokensUsed > 0) {
                    $this->quotaService->deduct($request->workspaceId, $request->providerCode, $tokensUsed);
                }

                return new ChatResponse(
                    text: $text,
                    tokensUsed: $tokensUsed,
                    modelId: $rawResponse['model'] ?? '',
                    providerCode: $request->providerCode,
                    toolCalls: $allToolCalls,
                );
            }

            // ③ 执行工具调用
            $toolResults = [];
            foreach ($toolCalls as $tc) {
                $toolResult = null;
                if ($registry && $sessionId) {
                    $toolResult = $registry->execute(
                        $tc['name'],
                        $tc['arguments'],
                        $request->workspaceId,
                        $agentType,
                        $sessionId,
                    );
                }
                $toolResults[] = [
                    'tool_call_id' => $tc['id'],
                    'name' => $tc['name'],
                    'result' => $toolResult,
                ];
                $allToolCalls[] = $tc;
            }

            // ④ 将 tool_calls + tool_results 追加到 messages
            $messages[] = [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => array_map(fn ($tc) => [
                    'id' => $tc['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $tc['name'],
                        'arguments' => json_encode($tc['arguments'], JSON_UNESCAPED_UNICODE),
                    ],
                ], $toolCalls),
            ];

            foreach ($toolResults as $tr) {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tr['tool_call_id'],
                    'content' => json_encode($tr['result'], JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        throw new RuntimeException("工具调用超过最大轮数({$maxIterations})，已自动终止并回退B型默认逻辑");
    }

    /**
     * 调用 LLM 原始接口（返回完整 JSON）。
     */
    private function callLlmRaw(string $providerCode, \App\Models\AiModel $aiModel, array $messages, array $options, array $tools): array
    {
        $adapter = $this->adapterFactory->createByCode(
            $providerCode,
            (string) ($aiModel->getRawOriginal('api_key') ?? ''),
            $aiModel->model_id
        );

        // 构建带 tools 的请求
        $bodyOptions = array_merge($options, ['tools' => $tools]);
        if ($tools !== []) {
            $bodyOptions['tool_choice'] = 'auto';
        }

        return $adapter->chat($aiModel->model_id, $messages, $bodyOptions);
    }

    /**
     * 从 LLM 响应中提取 tool_calls（归一化各厂商格式差异）。
     */
    private function extractToolCalls(array $rawResponse): array
    {
        $message = $rawResponse['choices'][0]['message'] ?? [];
        $toolCalls = $message['tool_calls'] ?? [];

        if ($toolCalls === [] || $toolCalls === null) {
            return [];
        }

        $normalized = [];
        foreach ($toolCalls as $tc) {
            $func = $tc['function'] ?? $tc['tool_function'] ?? [];
            $args = $func['arguments'] ?? $func['input'] ?? '{}';
            if (is_string($args)) {
                $args = json_decode($args, true) ?: [];
            }
            $normalized[] = [
                'id' => $tc['id'] ?? ('call_' . uniqid()),
                'name' => $func['name'] ?? '',
                'arguments' => $args,
            ];
        }

        return $normalized;
    }

    /**
     * 从 LLM 响应中提取纯文本（归一化各厂商格式差异）。
     */
    private function extractText(string $providerCode, array $rawResponse): string
    {
        // OpenAI 兼容格式
        $text = $rawResponse['choices'][0]['message']['content'] ?? '';

        // 文心一言格式
        if ($providerCode === 'ernie' && $text === '') {
            $text = $rawResponse['result'] ?? '';
        }

        return (string) $text;
    }

    /**
     * 解析 AiModel 记录（带 provider 关联）。
     */
    private function resolveAiModel(string $providerCode, string $modelId): AiModel
    {
        $query = AiModel::query()
            ->where('status', 'active')
            ->where('model_id', $modelId);

        // 如果有 provider_id 关联
        $provider = AiModelProvider::query()->where('provider_code', $providerCode)->first();
        if ($provider) {
            $query->where('provider_id', (int) $provider->id);
        }

        $aiModel = $query->first();

        if (! $aiModel) {
            // 宽松匹配：找不到 provider 关联时，退回到 api_url 匹配
            $aiModel = AiModel::query()
                ->where('status', 'active')
                ->where('model_id', $modelId)
                ->first();
        }

        if (! $aiModel) {
            throw new RuntimeException("AI 模型不可用: {$providerCode}/{$modelId}");
        }

        return $aiModel;
    }

    /**
     * 更新模型用量统计。
     */
    private function incrementModelUsage(AiModel $aiModel): void
    {
        AiModel::query()->whereKey((int) $aiModel->id)->update([
            'used_today' => \Illuminate\Support\Facades\DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => \Illuminate\Support\Facades\DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);
    }
}
