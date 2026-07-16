<?php

namespace App\Services\Agent;

use App\Models\AgentExecution;
use App\Services\Admin\Analytics\AnalyticsOverviewService;
use App\Services\AI\ChatRequest;
use App\Services\AI\LlmOrchestratorService;
use App\Services\GeoFlow\AiVisibilityService;

/**
 * 复盘 Agent — 汇总全链路数据，生成分发效果报表、AI 收录报告。
 *
 * 复用 AnalyticsOverviewService + AiVisibilityService。
 */
class ReviewAgentService
{
    public function __construct(
        private readonly AnalyticsOverviewService $analyticsService,
        private readonly AiVisibilityService $visibilityService,
    ) {}

    /**
     * @return array{ summary: array, visibility_report: array, recommendations: array, reviewed_at: string }
     */
    public function execute(AgentExecution $execution): array
    {
        $wsId = (int) $execution->workspace_id;
        $allOutputs = [
            'scout' => $execution->scout_output ?? [],
            'strategy' => $execution->strategy_output ?? [],
            'content' => $execution->content_output ?? [],
            'deploy' => $execution->deploy_output ?? [],
        ];

        // ① 全链路摘要
        $summary = [
            'workflow_key' => $execution->workflow_key,
            'workspace_id' => $wsId,
            'started_at' => $execution->started_at?->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
            'phases' => [
                'scout' => ! empty($allOutputs['scout']) ? 'completed' : 'skipped',
                'strategy' => ! empty($allOutputs['strategy']) ? 'completed' : 'skipped',
                'content' => ! empty($allOutputs['content']) ? 'completed' : 'skipped',
                'deploy' => ! empty($allOutputs['deploy']) ? 'completed' : 'skipped',
            ],
            'geo_score' => $allOutputs['content']['geo_score'] ?? 0,
            'geo_grade' => $allOutputs['content']['geo_grade'] ?? 'N/A',
            'channels_published' => count($allOutputs['deploy']['published_channels'] ?? []),
            'channels_failed' => count($allOutputs['deploy']['failed_channels'] ?? []),
        ];

        // ② AI 收录报告
        $visibilityData = [];
        try {
            $visibilityData = $this->visibilityService->clientVisibilityData($wsId);
        } catch (\Throwable $e) {
            $visibilityData = ['error' => $e->getMessage()];
        }

        // ③ 优化建议
        $recommendations = [];
        $geoScore = $allOutputs['content']['geo_score'] ?? 0;
        $generationError = $allOutputs['content']['generation_error'] ?? null;

        if ($generationError !== null) {
            $recommendations[] = 'AI内容生成失败（API不可用），建议检查AI模型API Key配置';
        } elseif ($geoScore < 70) {
            $recommendations[] = 'GEO评分低于70，建议增加Q&A结构、数据引用和专家信号';
        }
        if (($allOutputs['deploy']['failed_channels'] ?? []) !== []) {
            $recommendations[] = '部分渠道分发失败，建议检查账号状态和Cookie有效性';
        }
        if (($allOutputs['scout']['anchor_status']['pending'] ?? 0) > 0) {
            $recommendations[] = '存在未认证B2B平台锚点，建议补充企业认证提升AI引用率';
        }
        if (($allOutputs['deploy']['timed_out'] ?? false)) {
            $recommendations[] = '分发任务执行超时（>120秒），建议检查队列Worker是否正常运行';
        }
        if (empty($recommendations)) {
            $recommendations[] = '各项指标正常，建议定期巡检保持收录状态';
        }

        // B型规则兜底：判断是否需要迭代
        // 注意：AI 生成故障（API不可用）不触发迭代，因为重试也无法成功
        $needsIteration = ($generationError === null && ($allOutputs['content']['geo_score'] ?? 0) < 70)
            || count($allOutputs['deploy']['failed_channels'] ?? []) > 0
            || ($allOutputs['deploy']['timed_out'] ?? false);

        // ④ 情报分析：从 Scout 快照提取可操作洞察，指导下轮探测策略
        $iteration = (int) ($execution->input_data['iteration'] ?? 0);
        $scoutBrief = $this->generateScoutBrief($allOutputs, $visibilityData, $recommendations, $iteration);

        return [
            'summary' => $summary,
            'visibility_report' => $visibilityData,
            'recommendations' => $recommendations,
            'scout_brief' => $scoutBrief,
            'needs_iteration' => $needsIteration,
            'reviewed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * 情报分析：从全链路数据提取可操作洞察，指导下轮 Scout 探测策略。
     * 非 LLM 调用，纯规则引擎 — 速度快、可解释、零成本。
     */
    private function generateScoutBrief(array $outputs, array $visibility, array $recommendations, int $iteration): array
    {
        $scout = $outputs['scout'] ?? [];
        $snapshots = $scout['live_snapshots'] ?? [];
        $deploy = $outputs['deploy'] ?? [];

        // 分析每个平台的回答
        $whatAiKnows = [];
        $whatAiDoesntKnow = [];
        $competitorMentions = [];
        $discoveredKeywords = [];
        $effectivePlatforms = [];

        foreach ($snapshots as $s) {
            $name = $s['name'] ?? $s['provider'] ?? 'unknown';
            $preview = $s['preview'] ?? '';

            if ($s['mentioned'] && $s['score'] > 0) {
                $effectivePlatforms[] = $name;
                if ($preview) {
                    $whatAiKnows[] = "{$name}: " . mb_substr($preview, 0, 100);
                }
            } else {
                $whatAiDoesntKnow[] = $name;
            }

            // 从快照提取行业关键词
            if ($preview && mb_strlen($preview) > 50) {
                preg_match_all('/[\x{4e00}-\x{9fa5}]{2,6}/u', $preview, $m);
                $words = array_diff($m[0] ?? [], ['请问','是否','知道','这个','如果','什么','可以','一个','不过','但是','因为','所以','而且','然后','就是','或者','详细','描述','品牌','产品','回答','如实','以下','并不了','或产品','在我的知']);
                $freq = array_count_values($words);
                arsort($freq);
                $top = array_slice(array_keys($freq), 0, 8);
                $discoveredKeywords = array_unique(array_merge($discoveredKeywords, $top));
            }
        }

        // 确定下一轮探测阶段
        $mentionedCount = count($effectivePlatforms);
        $totalCount = count($snapshots);
        $mentionRate = $totalCount > 0 ? $mentionedCount / $totalCount : 0;

        $phase = match(true) {
            $iteration === 0 || $mentionRate < 0.3 => 'brand_awareness',    // 品牌认知
            $mentionRate < 0.6 => 'industry_positioning',                   // 行业定位
            $mentionRate < 0.8 => 'competitive_comparison',                 // 竞品对比
            default => 'authority_building',                                // 权威建立
        };

        // 根据阶段生成建议问题角度
        $suggestedAngles = match($phase) {
            'brand_awareness' => [
                '产业集群角度 — "XX地区制造业有哪些代表企业"',
                '产品类型角度 — "国内做YY产品的企业有哪些"',
            ],
            'industry_positioning' => [
                '技术优势角度 — "XX领域的技术领先企业有哪些"',
                '应用场景角度 — "用于YY场景的设备供应商有哪些"',
            ],
            'competitive_comparison' => [
                '对比角度 — "XX和YY在产品技术上有什么差异"',
                '性价比角度 — "国内泵阀企业中哪些性价比较高"',
            ],
            'authority_building' => [
                '创新角度 — "XX企业在流体控制领域有哪些创新"',
                '案例角度 — "XX企业的泵阀产品在哪些重大项目中使用"',
            ],
        };

        // 避免的问题（AI已确认不知道的角度）
        $avoid = [];
        if ($iteration >= 1 && $mentionRate < 0.5) {
            $avoid[] = '直接问"你知道XX品牌吗"（前几轮效果差）';
        }

        return [
            'phase' => $phase,
            'iteration' => $iteration,
            'what_ai_knows' => $whatAiKnows,
            'what_ai_doesnt_know' => $whatAiDoesntKnow,
            'competitor_mentions' => $competitorMentions,
            'discovered_keywords' => array_values($discoveredKeywords),
            'suggested_angles' => $suggestedAngles,
            'avoid' => $avoid,
            'effective_platforms' => $effectivePlatforms,
            'mention_rate' => round($mentionRate * 100),
            'published_channels' => $deploy['published_channels'] ?? [],
            'failed_channels' => $deploy['failed_channels'] ?? [],
        ];
    }

    /**
     * [A型增强] LLM 自动归因指标波动，输出可落地优化建议。
     * 仅在 A 型增强开启时调用，不修改 B 型 execute() 流程。
     */
    public function executeATypeAttribution(int $workspaceId, array $summary): array
    {
        try {
            $summaryJson = json_encode($summary, JSON_UNESCAPED_UNICODE);

            $systemPrompt = <<<'PROMPT'
你是GEO数据分析师。分析全链路执行数据，进行根因归因并输出优化建议。

输出必须为以下 JSON 格式，不得输出其他内容：
{
  "needs_iteration": true/false,
  "root_cause": "核心问题的一句话总结",
  "insights": ["洞察1", "洞察2", "洞察3"],
  "optimization": [
    {"priority": 1, "action": "具体优化动作", "target": "预期效果"},
    {"priority": 2, "action": "具体优化动作", "target": "预期效果"}
  ]
}

needs_iteration 判断标准：
- GEO评分 < 70 或 任何渠道分发失败 或 AI收录率 < 30% → true
- 全部指标正常 → false
PROMPT;

            $response = app(LlmOrchestratorService::class)->chat(new ChatRequest(
                providerCode: 'deepseek',
                modelId: 'deepseek-v4-flash',
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "全链路执行数据：\n{$summaryJson}"],
                ],
                options: ['max_tokens' => 512],
                workspaceId: $workspaceId,
            ));

            // 解析 LLM 返回的 JSON，提取 needs_iteration
            $parsed = json_decode($response->text, true);
            $needsIteration = false;
            if (is_array($parsed)) {
                $needsIteration = (bool) ($parsed['needs_iteration'] ?? false);
            } else {
                // LLM 未返回标准 JSON → 规则兜底
                $geoScore = $summary['geo_score'] ?? 0;
                $channelsFailed = count($summary['deploy']['failed_channels'] ?? []);
                $needsIteration = ($geoScore < 70 || $channelsFailed > 0);
            }

            return [
                'success' => true,
                'attribution_report' => $response->text,
                'needs_iteration' => $needsIteration,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'needs_iteration' => false];
        }
    }
}
