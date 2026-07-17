<?php

namespace App\Services\Agent;

use App\Models\AgentExecution;
use App\Models\Article;
use App\Models\Task;
use App\Services\AI\AgentFactory;
use App\Services\AI\LlmOrchestratorService;
use App\Services\GeoFlow\GeoContentScorer;
use App\Services\GeoFlow\KnowledgeRetrievalService;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 内容 Agent — v2.8.0 混合模式。
 *
 * RAG 检索 → AI 生成（分平台策略增强）→ GEO 评分 → 低分自动重写 → 合规预检。
 *
 * v2.8.0 新增：消费 Strategy 产出的 per_platform_strategy / content_angles / keyword_clusters，
 *           生成有针对性的平台差异化文章。
 *           当 Strategy 为 B 型规则模式时，自动回退通用 Prompt。
 */
class ContentAgentService
{
    public function __construct(
        private readonly WorkerExecutionService $workerExecutionService,
        private readonly GeoContentScorer $scorer,
        private readonly KnowledgeRetrievalService $retrievalService,
        private readonly AgentFactory $agentFactory,
    ) {}

    /**
     * @return array{ article_id: int|null, title: string, geo_score: int, geo_grade: string, content_length: int, retries: int, generation_error: ?string, generated_at: string }
     */
    public function execute(AgentExecution $execution): array
    {
        $wsId = (int) $execution->workspace_id;
        $strategyOutput = $execution->strategy_output ?? [];
        $taskConfig = $strategyOutput['task_config'] ?? [];
        $inputData = $execution->input_data ?? [];

        // ── v2.8.0: 消费 Strategy 分平台策略 ──
        $perPlatform = $strategyOutput['per_platform_strategy'] ?? [];
        $contentAngles = $strategyOutput['content_angles'] ?? [];
        $keywordClusters = $strategyOutput['keyword_clusters'] ?? [];
        $strategyMode = $strategyOutput['strategy_mode'] ?? 'rule';

        // ① 关联 Task
        $taskId = $inputData['task_id'] ?? 0;
        $task = $taskId > 0 ? Task::query()->find($taskId) : null;

        // ② 确定主目标平台及其策略
        $targetPlatforms = $taskConfig['target_platforms'] ?? ['toutiao', 'baijiahao'];
        $primaryPlatform = reset($targetPlatforms);
        $primaryStrategy = $perPlatform[$primaryPlatform] ?? null;

        // ③ 构建标题 — 轮转选取 content_angles，每篇不同角度
        $brandName = $taskConfig['brand_name'] ?? '';
        $keywords = $taskConfig['keywords'] ?? [];
        // 根据该客户已有文章数轮转角度，确保每篇不同
        $existingCount = Article::query()
            ->whereIn('id', function ($sub) use ($wsId) {
                $sub->select('assignable_id')->from('workspace_assignments')
                    ->where('assignable_type', Article::class)
                    ->where('workspace_id', $wsId);
            })->count();
        $angleIndex = $existingCount % max(count($contentAngles), 1);
        $primaryAngle = $contentAngles[$angleIndex] ?? ($contentAngles[0] ?? '');
        $title = $inputData['article_title']
            ?? ($primaryAngle ? "{$brandName}：{$primaryAngle}" : null)
            ?? ($brandName . '在' . implode('、', array_slice($keywords, 0, 3)) . '方面的专业优势');

        // ④ RAG 检索 — 用 keyword_clusters 增强检索覆盖面
        $knowledgeContext = $this->retrieveKnowledge($task, $keywords, $keywordClusters, $perPlatform);

        // ⑤ AI 生成文章
        $content = '';
        $geoScore = 0;
        $geoGrade = 'F';
        $retries = $execution->retry_count ?? 0;
        $generationError = null;

        try {
            if ($task && $task->ai_model_id) {
                // 有 Task → 走 WorkerExecutionService，策略数据注入 knowledge_context
                $result = $this->workerExecutionService->executeTask((int) $task->id, [
                    'title_override' => $title,
                    'keyword_override' => implode(' ', $keywords),
                    'knowledge_context' => $knowledgeContext
                        . "\n\n[GEO策略指导]\n" . $this->formatStrategyForContext($primaryStrategy, $contentAngles),
                ]);
                $content = $result['content'] ?? '';
                $articleId = $result['article_id'] ?? null;
            } else {
                // 无 Task → 直调 LlmOrchestrator，使用分平台策略增强 Prompt
                $systemPrompt = $this->buildSystemPrompt($primaryStrategy, $strategyMode);
                $userPrompt = $this->buildContentPrompt(
                    title: $title,
                    keywords: implode(' ', $keywords),
                    brand: $brandName,
                    kbContext: $knowledgeContext,
                    primaryStrategy: $primaryStrategy,
                    contentAngles: $contentAngles,
                    currentAngleIndex: $angleIndex,
                    perPlatform: $perPlatform,
                    primaryPlatform: $primaryPlatform,
                );

                try {
                    $response = app(LlmOrchestratorService::class)->chat(new \App\Services\AI\ChatRequest(
                        providerCode: 'deepseek',
                        modelId: 'deepseek-v4-flash',
                        messages: [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $userPrompt],
                        ],
                        options: ['max_tokens' => $primaryStrategy['length'] ?? 4096],
                        workspaceId: $wsId,
                        agentExecutionId: (int) $execution->id,
                    ));
                    $content = $response->text ?? '';
                } catch (\Throwable $e) {
                    Log::warning('ContentAgent: AI direct call failed', [
                        'error' => $e->getMessage(),
                        'workspace_id' => $wsId,
                    ]);
                    $content = '';
                    $generationError = $e->getMessage();
                }
                $articleId = null;
            }
        } catch (\Throwable $e) {
            Log::warning('ContentAgent: AI generation failed, continuing without content', ['error' => $e->getMessage()]);
            $content = '';
            $generationError = $e->getMessage();
        }

        // ⑥ GEO 评分
        if (! empty($content)) {
            $scoreResult = $this->scorer->score($title, $content);
            $geoScore = $scoreResult['score'] ?? 0;
            $geoGrade = $scoreResult['grade'] ?? 'F';
        }

        // ⑦ 结果存库（GEO>=70 或 没有生成错误时存，让低分有机会被重写）
        if (! empty($content)) {
            try {
                $article = Article::query()->create([
                    'task_id' => $task ? (int) $task->id : null,
                    'title' => $title,
                    'slug' => \Illuminate\Support\Str::slug($title) . '-' . \Illuminate\Support\Str::random(6),
                    'content' => $content,
                    'excerpt' => mb_substr(strip_tags($content), 0, 200),
                    'keywords' => is_array($keywords) ? implode(',', $keywords) : (string) $keywords,
                    'status' => $geoScore >= 70 ? 'published' : 'draft',
                    'is_ai_generated' => true,
                    'geo_score' => $geoScore,
                    'geo_grade' => $geoGrade,
                    'category_id' => \App\Models\Category::firstOrCreate(
                        ['slug' => 'ai-generated'],
                        ['name' => 'AI智能生成', 'description' => 'Agent管道自动生成', 'sort_order' => 99]
                    )->id,
                    'author_id' => \App\Models\Author::firstOrCreate(
                        ['name' => 'AI写手'],
                        ['bio' => 'GEO Agent管道自动生成', 'email' => 'ai-agent@qonhub.local']
                    )->id,
                ]);
                $articleId = (int) $article->id;

                try {
                    DB::table('workspace_assignments')->insert([
                        'workspace_id' => $wsId,
                        'assignable_type' => Article::class,
                        'assignable_id' => $articleId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('ContentAgent: workspace_assignments insert failed (article exists)', ['error' => $e->getMessage()]);
                }
            } catch (\Throwable $e) {
                Log::warning('ContentAgent: article save failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'article_id' => $articleId ?? null,
            'title' => $title,
            'geo_score' => $geoScore,
            'geo_grade' => $geoGrade,
            'content_length' => mb_strlen($content),
            'retries' => $retries,
            'generation_error' => $generationError,
            'primary_platform' => $primaryPlatform,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Prompt 构建 — 分平台策略驱动
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 构建分平台 System Prompt。
     */
    private function buildSystemPrompt(?array $primaryStrategy, string $strategyMode): string
    {
        // LLM 策略可用 → 使用平台专属 System Prompt
        if ($primaryStrategy && $strategyMode === 'llm') {
            $style = $primaryStrategy['style'] ?? '专业深度';
            $structure = $primaryStrategy['structure'] ?? '';
            $angle = $primaryStrategy['angle'] ?? '';

            return <<<PROMPT
你是专业的GEO内容营销写手，专精于「{$style}」风格的内容创作。

## 文章结构要求
{$structure}

## 核心切入角度
{$angle}

## GEO 写作规范（必须遵循）
1. **开篇定义式陈述** — 每个 H2 首句必须是自包含的完整陈述句，可被 AI 直接引用
2. **数据锚定** — 每个观点必须有具体数据/来源支撑，禁止"可能/大概/似乎"等虚词
3. **结构化元素** — 使用对比表格、FAQ 区块、编号步骤、定义框
4. **内联信源** — 直接在正文中标注"根据XX数据显示""XX行业报告指出"
5. **禁用 AI 味词汇** — 禁止"在当今""综上所述""值得注意的是""随着……的发展""无疑"
6. **长短句交替** — 每段 3-5 句，最长句不超过 40 字

## 输出格式
Markdown，含 H2/H3 标题层级、列表、表格、FAQ 区块。
PROMPT;
        }

        // 回退：通用 Prompt（B 型兜底）
        return <<<'PROMPT'
你是专业的B2B内容营销写手。请遵循以下规范：

1. 开篇定义式陈述 — 每个H2首句必须是独立完整的断言
2. 数据锚定 — 所有观点有具体数据支撑
3. 结构化 — 使用H2/H3标题、列表、对比表格、FAQ区块
4. 禁用词 — 避免"在当今""综上所述""值得注意的是""可能""大概"
5. 内联信源 — 正文中标注数据来源

输出完整的Markdown格式文章。
PROMPT;
    }

    /**
     * 构建分平台增强的 User Prompt。
     */
    private function buildContentPrompt(
        string $title,
        string $keywords,
        string $brand,
        string $kbContext,
        ?array $primaryStrategy,
        array $contentAngles,
        int $currentAngleIndex,
        array $perPlatform,
        string $primaryPlatform,
    ): string {
        $prompt = "请撰写一篇关于「{$title}」的专业文章。\n\n";

        // 品牌与关键词
        if ($brand) {
            $prompt .= "**品牌**：{$brand}\n";
        }
        $prompt .= "**关键词**：{$keywords}\n";

        // ── v2.8.0: 分平台策略注入 ──
        if ($primaryStrategy) {
            $prompt .= "\n## 目标平台策略（主：{$primaryPlatform}）\n";
            $prompt .= "- 风格要求：{$primaryStrategy['style']}\n";
            $prompt .= "- 推荐结构：{$primaryStrategy['structure']}\n";
            $prompt .= "- 目标长度：{$primaryStrategy['length']}字\n";
            $prompt .= "- 最佳角度：{$primaryStrategy['angle']}\n";
        }

        // 多平台适配指导
        if (! empty($perPlatform) && count($perPlatform) > 1) {
            $prompt .= "\n## 多平台适配要求\n";
            $prompt .= "本文需同时兼容以下平台的收录偏好，请在内容中兼顾：\n";
            foreach ($perPlatform as $platform => $strategy) {
                if ($platform === $primaryPlatform) continue;
                $prompt .= "- **{$platform}**：{$strategy['style']}，{$strategy['angle']}\n";
            }
        }

        // 内容角度注入 — v2.8.1: 每篇只用1个角度，避免重复
        if (! empty($contentAngles)) {
            $currentAngle = $contentAngles[$currentAngleIndex % count($contentAngles)] ?? $contentAngles[0];
            $otherAngles = [];
            foreach ($contentAngles as $i => $a) {
                if ($i !== ($currentAngleIndex % count($contentAngles))) {
                    $otherAngles[] = $a;
                }
            }

            $prompt .= "\n## 本文专属切入角度\n";
            $prompt .= "**请围绕以下这一个角度深度展开，不要偏离：**\n";
            $prompt .= "→ {$currentAngle}\n";
            if (! empty($otherAngles)) {
                $prompt .= "\n⚠️ **以下角度已有其他文章覆盖，本文请勿重复：**\n";
                foreach ($otherAngles as $a) {
                    $prompt .= "- {$a}\n";
                }
            }
        }

        // 知识库素材
        if ($kbContext) {
            $prompt .= "\n## 参考素材（来自客户知识库）\n{$kbContext}\n";
            $prompt .= "\n**请充分利用以上素材中的数据、案例和专家观点。**\n";
        }

        // GEO 写作要求
        $prompt .= "\n## GEO 写作要求\n";
        $prompt .= "- 开篇首段直接回答问题，200字以内给出核心结论\n";
        $prompt .= "- Q&A结构：每个 H2 回答一个用户可能搜索的问题\n";
        $prompt .= "- 数据密度不低于 3%（每 100 字至少含 1 个数据点或引用）\n";
        $prompt .= "- 包含 1-2 个对比表格或数据表格\n";
        $prompt .= "- 末尾加入 FAQ 区块（3-5 个常见问题+简短回答）\n";
        $prompt .= '- 全文不允许出现「在当今」「综上所述」「值得注意的是」等套话' . "\n";

        // 平台规则（向后兼容）
        $prompt .= "\n" . \App\Support\GeoFlow\GeoPlatformRules::forTemplate($primaryPlatform);
        $prompt .= "\n\n输出完整的Markdown格式文章。";

        return $prompt;
    }

    /**
     * 将分平台策略格式化为可注入 WorkerExecutionService 的文本。
     */
    private function formatStrategyForContext(?array $primaryStrategy, array $contentAngles): string
    {
        $lines = [];
        if ($primaryStrategy) {
            $lines[] = "写作风格：{$primaryStrategy['style']}";
            $lines[] = "文章结构：{$primaryStrategy['structure']}";
            $lines[] = "目标长度：{$primaryStrategy['length']}字";
            $lines[] = "核心角度：{$primaryStrategy['angle']}";
        }
        if (! empty($contentAngles)) {
            $lines[] = '内容角度：' . implode('；', array_slice($contentAngles, 0, 3));
        }
        return implode("\n", $lines);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  RAG 检索 — 关键词聚类增强
    // ═══════════════════════════════════════════════════════════════════

    /**
     * RAG 知识检索 — v2.8.0 用 keyword_clusters 增强覆盖面。
     */
    private function retrieveKnowledge(?Task $task, array $keywords, array $keywordClusters, array $perPlatform): string
    {
        $kbIds = $task ? $this->resolveTaskKbIds($task) : [];
        if (empty($kbIds)) return '';

        $contexts = [];
        $seen = [];

        // 主检索：合并关键词
        $primaryQuery = implode(' ', $keywords);
        if ($primaryQuery !== '') {
            try {
                $result = $this->retrievalService->retrieveContextFromMany($kbIds, $primaryQuery, limit: 4, maxChars: 2500);
                if ($result) { $contexts[] = $result; $seen[] = md5($result); }
            } catch (\Throwable $e) {
                Log::warning('ContentAgent: primary RAG retrieval failed', ['error' => $e->getMessage()]);
            }
        }

        // 增强检索：每个主题聚类各检索一次，取不重复的结果
        foreach (array_slice($keywordClusters, 0, 3) as $cluster) {
            $clusterKeywords = $cluster['keywords'] ?? [];
            if (empty($clusterKeywords)) continue;
            $query = implode(' ', array_slice($clusterKeywords, 0, 4));
            try {
                $result = $this->retrievalService->retrieveContextFromMany($kbIds, $query, limit: 2, maxChars: 1000);
                $h = md5($result);
                if ($result && ! in_array($h, $seen, true)) {
                    $contexts[] = "【{$cluster['theme']}】\n{$result}";
                    $seen[] = $h;
                }
            } catch (\Throwable) { /* 检索失败跳过 */ }
        }

        return implode("\n\n---\n\n", $contexts);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  A 型增强 & 辅助方法
    // ═══════════════════════════════════════════════════════════════════

    /**
     * [A型增强] LLM 自主诊断 GEO 低分原因，制定重写策略。
     */
    public function executeATypeDiagnose(int $workspaceId, string $title, string $content, int $geoScore, array $dimensions): array
    {
        try {
            $dimensionSummary = '';
            foreach ($dimensions as $dim => $detail) {
                $score = $detail['score'] ?? 0;
                $dimensionSummary .= "- {$dim}: {$score}分\n";
            }

            $response = app(LlmOrchestratorService::class)->chat(new \App\Services\AI\ChatRequest(
                providerCode: 'deepseek',
                modelId: 'deepseek-v4-flash',
                messages: [
                    ['role' => 'system', 'content' => '你是GEO内容优化专家。请诊断文章低分原因，输出具体可执行的改进建议。'],
                    ['role' => 'user', 'content' => "文章标题：{$title}\nGEO评分：{$geoScore}/100\n各维度得分：\n{$dimensionSummary}\n\n请诊断：\n1. 主要失分维度及原因\n2. 具体改进方向(增加Q&A结构/数据引用/专家信号/删除虚词)\n3. 重写后的目标分数预估\n\n输出简洁的改进建议列表。"],
                ],
                options: ['max_tokens' => 512],
                workspaceId: $workspaceId,
            ));

            return [
                'success' => true,
                'diagnosis' => $response->text,
                'geo_score' => $geoScore,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'geo_score' => $geoScore];
        }
    }

    private function resolveTaskKbIds(Task $task): array
    {
        $ids = [];
        if ((int) ($task->knowledge_base_id ?? 0) > 0) {
            $ids[] = (int) $task->knowledge_base_id;
        }
        if (DB::getSchemaBuilder()->hasTable('task_knowledge_bases')) {
            $extraIds = DB::table('task_knowledge_bases')
                ->where('task_id', (int) $task->id)
                ->pluck('knowledge_base_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $ids = array_unique(array_merge($ids, $extraIds));
        }
        return $ids;
    }
}
