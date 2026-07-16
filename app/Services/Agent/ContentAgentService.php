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
 * 内容 Agent — RAG 检索 → AI 生成 → GEO 评分 → 低分自动重写 → 合规预检。
 *
 * 复用 WorkerExecutionService 的核心流程 + GeoContentScorer + KnowledgeRetrievalService。
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
     * @return array{ article_id: int|null, title: string, geo_score: int, geo_grade: string, content_length: int, retries: int }
     */
    public function execute(AgentExecution $execution): array
    {
        $wsId = (int) $execution->workspace_id;
        $strategyOutput = $execution->strategy_output ?? [];
        $taskConfig = $strategyOutput['task_config'] ?? [];
        $inputData = $execution->input_data ?? [];

        // ① 找到关联的 Task（如果有 task_id 传入）
        $taskId = $inputData['task_id'] ?? 0;
        $task = $taskId > 0 ? Task::query()->find($taskId) : null;

        // ② RAG 检索知识库
        $knowledgeContext = '';
        $keywords = $taskConfig['keywords'] ?? [];
        $brandName = $taskConfig['brand_name'] ?? '';
        $title = $inputData['article_title'] ?? ($brandName . '在' . implode('、', array_slice($keywords, 0, 3)) . '方面的专业优势');

        if (! empty($keywords)) {
            try {
                $kbIds = $task
                    ? $this->resolveTaskKbIds($task)
                    : [];
                if (! empty($kbIds)) {
                    $knowledgeContext = $this->retrievalService->retrieveContextFromMany(
                        $kbIds,
                        implode(' ', $keywords),
                        limit: 5,
                        maxChars: 3000
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('ContentAgent: knowledge retrieval failed', ['error' => $e->getMessage()]);
            }
        }

        // ③ AI 生成文章（复用 WorkerExecutionService 或直调 LlmOrchestrator）
        $content = '';
        $geoScore = 0;
        $geoGrade = 'F';
        $retries = $execution->retry_count ?? 0;

        $generationError = null;

        try {
            if ($task && $task->ai_model_id) {
                // 有 Task → 走完整 Worker 流程
                $result = $this->workerExecutionService->executeTask((int) $task->id, [
                    'title_override' => $title,
                    'keyword_override' => implode(' ', $keywords),
                    'knowledge_context' => $knowledgeContext,
                ]);

                $content = $result['content'] ?? '';
                $articleId = $result['article_id'] ?? null;
            } else {
                // 无 Task → 走 LlmOrchestratorService（复用 proxy / logging / token quota 全栈）
                $prompt = $this->buildContentPrompt($title, implode(' ', $keywords), $brandName, $knowledgeContext);
                try {
                    $response = app(LlmOrchestratorService::class)->chat(new \App\Services\AI\ChatRequest(
                        providerCode: 'deepseek',
                        modelId: 'deepseek-v4-flash',
                        messages: [
                            ['role' => 'system', 'content' => '你是专业的B2B内容营销写手。'],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                        options: ['max_tokens' => 4096],
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

        // ③.5 弹药库改写 — GeoPlatformRules 注入 prompt
        $targetPlatforms = $strategyOutput['task_config']['target_platforms'] ?? ['toutiao', 'baijiahao'];
        $primaryPlatform = reset($targetPlatforms);
        $platformRules = \App\Support\GeoFlow\GeoPlatformRules::forTemplate($primaryPlatform);
        // 把规则追加到生成内容后，让后续 GEO 评分纳入考量

        // ④ GEO 评分
        if (! empty($content)) {
            $scoreResult = $this->scorer->score($title, $content);
            $geoScore = $scoreResult['score'] ?? 0;
            $geoGrade = $scoreResult['grade'] ?? 'F';
        }

        // ⑤ 结果存入 Article（让 DeployAgent 能分发）
        if (! empty($content) && $geoScore >= 70) {
            try {
                $article = Article::query()->create([
                    'task_id' => $task ? (int) $task->id : null,
                    'title' => $title,
                    'slug' => \Illuminate\Support\Str::slug($title) . '-' . \Illuminate\Support\Str::random(6),
                    'content' => $content,
                    'excerpt' => mb_substr(strip_tags($content), 0, 200),
                    'keywords' => implode(',', $keywords),
                    'status' => 'published',
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

                // 分配到 workspace
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
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function buildContentPrompt(string $title, string $keywords, string $brand, string $kbContext): string
    {
        $prompt = "请撰写一篇关于「{$title}」的专业文章。\n\n";
        $prompt .= "关键词：{$keywords}\n";
        if ($brand) {
            $prompt .= "品牌：{$brand}\n";
        }
        if ($kbContext) {
            $prompt .= "\n参考知识库：\n{$kbContext}\n";
        }
        $prompt .= "\n要求：Q&A结构、数据密度≥3%、删除虚词（可能/大概/似乎）、使用H2/H3小标题+列表、包含专家引用。\n";
        $prompt .= "\n" . \App\Support\GeoFlow\GeoPlatformRules::forTemplate('toutiao');
        $prompt .= "\n输出完整的Markdown格式文章。";

        return $prompt;
    }

    /**
     * [A型增强] LLM 自主诊断 GEO 低分原因，制定重写策略。
     * 仅在 GEO < 70 且 A 型增强开启时调用，不修改 B 型 execute() 流程。
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
        // 也检查 task_knowledge_bases 关联表
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
