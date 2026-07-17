<?php

namespace App\Services\Agent;

use App\Models\AgentExecution;
use App\Models\Task;
use App\Services\AI\ChatRequest;
use App\Services\AI\LlmOrchestratorService;
use App\Services\GeoFlow\GeoContentScorer;
use App\Services\GeoFlow\WorkspaceService;
use Illuminate\Support\Facades\Log;

/**
 * 策略 Agent — 混合模式：LLM 智能分析（A型）+ 规则引擎（B型兜底）。
 *
 * A 型（v2.8.0 新增）：调 LLM 分析 Scout 全量数据，输出分平台内容策略。
 * B 型（兜底）：正则提取高频词与缺口排序，保证 LLM 故障时不断流。
 *
 * 输出同时兼容 ContentAgent 和 DeployAgent 的消费格式。
 */
class StrategyAgentService
{
    public function __construct(
        private readonly GeoContentScorer $scorer,
        private readonly WorkspaceService $workspaceService,
    ) {}

    /**
     * 混合执行：A 型 LLM 分析优先，失败时回退 B 型规则。
     *
     * @return array{ keywords: array, channel_plan: array, task_config: array, per_platform_strategy: array, content_angles: array, strategy_mode: string, estimated_geo_score: int }
     */
    public function execute(AgentExecution $execution): array
    {
        $wsId = (int) $execution->workspace_id;
        $scoutOutput = $execution->scout_output ?? [];
        $inputData = $execution->input_data ?? [];

        // ① 尝试 A 型 LLM 策略分析
        $aTypeResult = null;
        try {
            $aTypeResult = $this->executeATypeStrategy($execution);
        } catch (\Throwable $e) {
            Log::warning('StrategyAgent: A-type LLM analysis failed, falling back to B-type', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
            ]);
        }

        // ② B 型规则引擎（始终运行，保障基础数据完整）
        $keywords = array_unique(array_merge(
            $inputData['keywords'] ?? [],
            $this->extractKeywordsFromScout($scoutOutput)
        ));
        $brandName = $inputData['brand_name'] ?? '';
        $gaps = $scoutOutput['gaps'] ?? [];

        $channelPlan = [];
        foreach ($gaps as $gap) {
            $channelPlan[] = [
                'platform_key' => $gap['platform_key'],
                'platform_name' => $gap['platform_name'],
                'action' => $gap['action_needed'],
                'priority' => $gap['action_needed'] === 'register' ? 'high' : 'medium',
            ];
        }

        // A 型成功 → 用 LLM 策略增强 task_config
        if ($aTypeResult !== null) {
            $taskConfig = [
                'keywords' => $aTypeResult['keyword_clusters']
                    ? array_unique(array_merge(...array_map(
                        fn ($c) => $c['keywords'] ?? [],
                        $aTypeResult['keyword_clusters']
                    )))
                    : $keywords,
                'keyword_clusters' => $aTypeResult['keyword_clusters'],
                'target_platforms' => array_slice($aTypeResult['priority_platforms'], 0, 5),
                'brand_name' => $brandName,
                'content_count' => $inputData['content_count'] ?? 3,
                'geo_threshold' => 70,
                'diagnosis' => $aTypeResult['diagnosis'] ?? '',
            ];

            return [
                'keywords' => $keywords,
                'channel_plan' => $channelPlan,
                'task_config' => $taskConfig,
                'per_platform_strategy' => $aTypeResult['per_platform_strategy'] ?? [],
                'content_angles' => $aTypeResult['content_angles'] ?? [],
                'keyword_clusters' => $aTypeResult['keyword_clusters'] ?? [],
                'strategy_mode' => 'llm',
                'estimated_geo_score' => $aTypeResult['estimated_geo_score'] ?? 65,
                'planned_at' => now()->toIso8601String(),
            ];
        }

        // B 型兜底
        $taskConfig = [
            'keywords' => $keywords,
            'target_platforms' => $inputData['platforms'] ?? ['toutiao', 'baijiahao'],
            'brand_name' => $brandName,
            'content_count' => $inputData['content_count'] ?? 3,
            'geo_threshold' => 70,
        ];

        $sampleTitle = $brandName ? "{$brandName}在" . implode('、', array_slice($keywords, 0, 2)) . "方面的优势" : '';
        $estimatedScore = $sampleTitle
            ? $this->scorer->quickScore($sampleTitle, '')
            : 50;

        return [
            'keywords' => $keywords,
            'channel_plan' => $channelPlan,
            'task_config' => $taskConfig,
            'per_platform_strategy' => [],
            'content_angles' => [],
            'keyword_clusters' => [],
            'strategy_mode' => 'rule',
            'estimated_geo_score' => $estimatedScore,
            'planned_at' => now()->toIso8601String(),
        ];
    }

    /**
     * [A 型] LLM 驱动的分平台内容策略生成。
     *
     * 输入 Scout 全量数据（各平台回答全文 + 锚点缺口 + 品牌收录状态），
     * 输出结构化策略 JSON — 包含分平台写作策略、关键词聚类、内容角度、优先级排序。
     *
     * 参考：MAGEO Planner Agent（ACL 2026）的 conditioned decomposition 模式，
     *       GenOptima 2026 中国 AI 平台分模型内容策略矩阵。
     *
     * @return array{ diagnosis: string, per_platform_strategy: array, keyword_clusters: array, content_angles: array, priority_platforms: array, estimated_geo_score: int }|null
     */
    public function executeATypeStrategy(AgentExecution $execution): ?array
    {
        $scoutOutput = $execution->scout_output ?? [];
        $inputData = $execution->input_data ?? [];
        $brandName = $inputData['brand_name'] ?? '';
        $existingKeywords = $inputData['keywords'] ?? [];

        // 收集 Scout 数据
        $liveSnapshots = $scoutOutput['live_snapshots'] ?? [];
        $gaps = $scoutOutput['gaps'] ?? [];
        $anchorStatus = $scoutOutput['anchor_status'] ?? [];
        $brandMentions = $scoutOutput['brand_mentions'] ?? [];

        if (empty($brandName) && empty($existingKeywords)) {
            return null; // 无任何输入，跳过 LLM 分析
        }

        // 构建 Scout 数据摘要（供 LLM 分析）
        $scoutDigest = $this->buildScoutDigest($liveSnapshots, $gaps, $anchorStatus, $brandMentions, $brandName, $existingKeywords);

        // v2.8.0: 从记忆库检索历史成功模式
        $memoryContext = '';
        try {
            $memories = app(MemoryService::class)->retrieveRelevant(
                workspaceId: (int) $execution->workspace_id,
                keywords: array_merge($existingKeywords, $scoutOutput['brand_mentions']['mentioned_platforms'] ?? []),
                platforms: array_column($gaps, 'platform_key'),
                limit: 5,
            );
            if (! empty($memories)) {
                $memoryContext = "## 历史记忆（本客户过去的成功模式）\n";
                foreach ($memories as $i => $memory) {
                    $metrics = $memory['metrics'] ?? [];
                    $memoryContext .= ($i + 1) . ". [{$memory['agent_type']}] GEO={$metrics['geo_score']}分，平台=" . implode(',', $metrics['effective_platforms'] ?? []) . "\n";
                    if ($memory['output_digest']) {
                        $memoryContext .= "   输出摘要：{$memory['output_digest']}\n";
                    }
                }
                $memoryContext .= "\n请参考以上历史成功经验来制定本次策略。\n";
            }
        } catch (\Throwable $e) {
            // 记忆检索失败不影响主流程
        }

        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->buildUserPrompt($brandName, $existingKeywords, $scoutDigest, $memoryContext);

        try {
            $response = app(LlmOrchestratorService::class)->chat(new ChatRequest(
                providerCode: 'deepseek',
                modelId: 'deepseek-v4-flash',
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                options: ['max_tokens' => 2048, 'temperature' => 0.4],
                workspaceId: (int) $execution->workspace_id,
                agentExecutionId: (int) $execution->id,
            ));

            $parsed = $this->parseStrategyJson($response->text ?? '');
            if ($parsed === null) {
                Log::warning('StrategyAgent: LLM returned invalid JSON, using B-type fallback', [
                    'execution_id' => $execution->id,
                    'raw_response' => mb_substr($response->text ?? '', 0, 500),
                ]);
                return null;
            }

            Log::info('StrategyAgent: A-type LLM strategy generated', [
                'execution_id' => $execution->id,
                'platforms' => array_keys($parsed['per_platform_strategy'] ?? []),
                'angles_count' => count($parsed['content_angles'] ?? []),
                'clusters_count' => count($parsed['keyword_clusters'] ?? []),
            ]);

            return $parsed;
        } catch (\Throwable $e) {
            Log::warning('StrategyAgent: A-type LLM call failed', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 构建 Scout 数据摘要 — 将原始快照数据压缩为 LLM 可消费的上下文。
     */
    private function buildScoutDigest(array $snapshots, array $gaps, array $anchorStatus, array $brandMentions, string $brandName, array $keywords): string
    {
        $lines = [];

        $lines[] = "品牌名称：{$brandName}";
        $lines[] = '已有关键词：' . (empty($keywords) ? '（无）' : implode('、', $keywords));
        $lines[] = '';

        // AI 平台实时搜索快照
        $lines[] = '=== AI 平台搜索结果 ===';
        if (empty($snapshots)) {
            $lines[] = '（无实时搜索数据）';
        } else {
            foreach ($snapshots as $s) {
                $name = $s['name'] ?? $s['provider'] ?? '未知平台';
                $mentioned = ($s['mentioned'] ?? false) ? '✅ 已收录' : '❌ 未收录';
                $score = $s['score'] ?? 0;
                $preview = $s['preview'] ?? '';
                $error = $s['error'] ?? '';
                $lines[] = "- {$name}：{$mentioned}（提及得分：{$score}）";
                if ($preview) {
                    $lines[] = "  回答摘要：" . mb_substr($preview, 0, 150);
                }
                if ($error) {
                    $lines[] = "  错误：{$error}";
                }
            }
        }
        $lines[] = '';

        // B2B 锚点缺口
        $lines[] = '=== B2B 锚点缺口 ===';
        $lines[] = '已认证：' . ($anchorStatus['certified'] ?? 0) . ' / 总计：' . ($anchorStatus['total_platforms'] ?? 0);
        $lines[] = '待认证：' . ($anchorStatus['pending'] ?? 0) . ' / 已过期：' . ($anchorStatus['expired'] ?? 0);
        if (! empty($gaps)) {
            $lines[] = '缺口清单：';
            foreach (array_slice($gaps, 0, 10) as $gap) {
                $lines[] = "  - {$gap['platform_name']}（{$gap['status']}）→ 需要 {$gap['action_needed']}";
            }
        }
        $lines[] = '';

        // 品牌收录统计
        $lines[] = '=== 品牌收录统计 ===';
        $lines[] = '被提及平台数：' . count($brandMentions['mentioned_platforms'] ?? []);
        $lines[] = '今日检测次数：' . ($brandMentions['total_checks_today'] ?? 0);
        $lines[] = '提及率：' . (($brandMentions['mention_rate'] ?? 0) * 100) . '%';

        return implode("\n", $lines);
    }

    /**
     * 构建系统 Prompt — 定义 GEO 策略分析师角色和输出格式。
     *
     * 分平台策略参考数据源：
     *   - MAGEO Preference Agent 引擎偏好画像（ACL 2026）
     *   - GenOptima 2026 中国 AI 平台分模型内容策略
     *   - iClick 2026 GEO Guide 各平台收录特征
     */
    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
你是 GEO（生成式引擎优化）策略分析师。你的任务是分析品牌在多个 AI 平台上的收录数据，
制定分平台内容策略，指导后续的内容创作和分发。

## 你必须了解的国内主流 AI 平台内容偏好

| 平台 | 内容偏好 | 推荐风格 | 推荐长度 |
|------|---------|---------|---------|
| DeepSeek | 深度推理、数据密度高、逻辑链完整、权威信源 | 深度长文 — 论点→论据→数据→结论 | 2500-3500字 |
| 豆包 | 场景化表达、实用指南、生活化语言、字节生态 | 实用指南 — 场景→问题→方案→案例 | 1200-1800字 |
| 通义千问 | 结构化数据、权威信源、B端专业场景、多源验证 | 专业白皮书 — 背景→分析→方案→对比 | 1800-2500字 |
| 元宝 | 微信生态、社交口碑、办公场景、实用攻略 | 社交口碑 — 用户评价→专家解读→数据 | 1200-1600字 |
| 文心一言 | 百度生态、知识图谱实体、百家号/百科权重高 | 百科型 — 定义→特征→分类→对比→趋势 | 1500-2000字 |
| Kimi | 长上下文、深度长文、行业报告、文档引用 | 深度解读 — 宏观背景→行业格局→案例拆解 | 2000-3000字 |
| 讯飞星火 | 教育/技术场景、逻辑推理、结构化表达 | 技术分析 — 技术原理→应用场景→优劣势 | 1500-2000字 |

## GEO 内容写作黄金法则

1. 开篇定义式陈述 — 每个 H2 必须以一句完整的自包含陈述句开头，可被 AI 直接引用
2. 数据锚定 — 每个观点必须有具体数据/来源支撑，避免模糊表述
3. 禁止 AI 味词汇 — 避免"在当今""综上所述""值得注意的是""随着……的发展"
4. 结构化元素 — 尽量使用对比表格、FAQ 区块、编号步骤、定义框
5. 内联信源 — 直接在正文中标注"根据XX数据显示"，而非脚注

## 输出格式（必须是严格 JSON，不得额外输出）

```json
{
  "diagnosis": "一句话诊断 — 品牌当前在AI平台上的收录状态概括",
  "per_platform_strategy": {
    "deepseek": {"style": "写作风格", "structure": "文章结构", "angle": "本平台最佳切入角度", "length": 3000},
    "doubao": {"style": "...", "structure": "...", "angle": "...", "length": 1500}
  },
  "keyword_clusters": [
    {"theme": "主题名", "keywords": ["词1", "词2", "词3", "词4", "词5"], "target_platform": "最适合的平台"}
  ],
  "content_angles": ["角度1 — 具体说明", "角度2 — 具体说明", "角度3 — 具体说明"],
  "priority_platforms": ["deepseek", "doubao", "qwen"],
  "estimated_geo_score": 65
}
```

JSON 规则：
- per_platform_strategy 只输出 Scout 数据中实际存在的平台
- keyword_clusters 输出 2-4 个主题聚类，每个含 4-6 个关键词
- content_angles 输出 3-5 个具体内容切入角度（不只是标签，要有一句说明）
- priority_platforms 按收录成功率从高到低排序
- estimated_geo_score 是 0-100 的整数，基于当前收录状态估算
PROMPT;
    }

    /**
     * 构建用户 Prompt — 本次分析的品牌、Scout 数据、历史记忆。
     */
    private function buildUserPrompt(string $brandName, array $keywords, string $scoutDigest, string $memoryContext = ''): string
    {
        // 确定分析重点
        $analysisFocus = empty($keywords)
            ? "品牌「{$brandName}」没有预设关键词，请从 Scout 数据中自行提取最有效的关键词策略"
            : "品牌「{$brandName}」预设关键词：" . implode('、', $keywords) . "，请结合 Scout 数据优化和扩展这些关键词";

        $prompt = "## 分析任务\n\n{$analysisFocus}。\n\n";
        if ($memoryContext !== '') {
            $prompt .= "{$memoryContext}\n\n";
        }
        $prompt .= "## Scout 原始数据\n\n{$scoutDigest}\n\n";
        $prompt .= <<<'PROMPT'
## 请根据以上数据输出策略 JSON

要求：
1. 如果某些平台没有搜索数据（未覆盖），不要在 per_platform_strategy 中包含它们
2. 关键词聚类应包含从 Scout 回答中新发现的高价值词（AI 已知道的相关概念）
3. 内容角度应针对 AI 收录缺口设计（AI 知道的少 → 补基础知识；AI 知道但模糊 → 加深权威感）
4. 优先级排序标准：已收录的平台优先维护，高流量平台优先攻克
5. 如果提供了历史记忆，优先参考过去的成功模式来制定策略
PROMPT;
        return $prompt;
    }

    /**
     * 解析 LLM 返回的策略 JSON。
     * 兼容 LLM 偶尔输出的 markdown 代码块包裹。
     */
    private function parseStrategyJson(string $rawText): ?array
    {
        $text = trim($rawText);
        if ($text === '') return null;

        // 移除可能的 markdown 代码块包裹
        if (preg_match('/```(?:json)?\s*\n?(.+?)\n?```/s', $text, $m)) {
            $text = trim($m[1]);
        }

        $parsed = json_decode($text, true);
        if (! is_array($parsed)) return null;

        // 必填字段校验
        $required = ['per_platform_strategy', 'keyword_clusters', 'content_angles', 'priority_platforms'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $parsed)) return null;
        }

        return $parsed;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  B 型规则引擎（LLM 故障时的兜底逻辑，保持 v2.7.0 行为不变）
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 从 Scout 的 live_snapshots 中提取品牌相关关键词。
     */
    private function extractKeywordsFromScout(array $scoutOutput): array
    {
        $snapshots = $scoutOutput['live_snapshots'] ?? [];
        if (empty($snapshots)) return [];

        $negativePatterns = [
            '很抱歉', '并不了解', '不太清楚', '没有相关', '无法提供',
            '暂未收录', '不知道', '没有记录', '没有找到', '未找到',
            'no information', "I don't know", 'cannot provide',
            '无法回答', '暂无数据', '没有相关记录',
        ];

        $texts = [];
        foreach ($snapshots as $s) {
            if (! empty($s['mentioned']) && ! empty($s['preview'])) {
                $preview = $s['preview'];
                foreach ($negativePatterns as $neg) {
                    if (mb_stripos($preview, $neg) !== false) {
                        continue 2;
                    }
                }
                $texts[] = $preview;
            }
        }
        if (empty($texts)) return [];

        $combined = implode(' ', $texts);
        preg_match_all('/[\x{4e00}-\x{9fa5}]{2,4}/u', $combined, $matches);
        $words = $matches[0] ?? [];

        $stopWords = ['请问', '是否', '知道', '这个', '如果', '什么', '可以', '一个', '不过', '但是', '因为', '所以', '而且', '然后', '就是', '或者', '详细', '描述', '品牌', '产品', '回答', '如实', '以下', '并不了', '或产品', '在我的知'];
        $words = array_diff($words, $stopWords);
        $freq = array_count_values($words);
        arsort($freq);

        return array_slice(array_keys($freq), 0, 10);
    }
}
