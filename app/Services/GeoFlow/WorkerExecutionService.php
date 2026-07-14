<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Responses\Data\FinishReason;
use RuntimeException;
use Throwable;

/**
 * Worker 任务执行器：将队列任务落地为文章记录（占位实现，先打通 worker/队列链路）。
 */
class WorkerExecutionService
{
    /**
     * 复用统一 API Key 解密组件，确保 worker 与后台配置端解密行为一致。
     */
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly KnowledgeChunkSyncService $knowledgeChunkSyncService,
        private readonly KnowledgeRetrievalService $knowledgeRetrievalService,
        private readonly DistributionOrchestrator $distributionOrchestrator
    ) {}

    /**
     * @return array{article_id:int|null, title:string, message:string, meta:array<string,mixed>}
     */
    public function executeTask(int $taskId, array $payload = []): array
    {
        /** @var Task|null $task */
        $task = Task::query()->find($taskId);
        if (! $task) {
            throw new RuntimeException('任务不存在');
        }

        if (($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
            throw new RuntimeException('任务未激活');
        }

        $publishResult = $this->publishDueDraftArticle($task);
        if ($publishResult !== null) {
            $this->distributionOrchestrator->enqueueForArticle((int) $publishResult['article_id']);

            return $publishResult;
        }

        $generationBlockReason = $this->getGenerationBlockReason($task);
        if ($generationBlockReason !== null) {
            return [
                'article_id' => null,
                'title' => '',
                'message' => $generationBlockReason,
                'meta' => [
                    'task_id' => (int) $task->id,
                    'action' => 'noop',
                    'reason' => $generationBlockReason,
                ],
            ];
        }

        $titleRow = $this->pickTitle($task);
        $author = $this->pickAuthor($task);
        $category = $this->pickCategory($task);
        $prompt = $task->prompt_id ? Prompt::query()->find((int) $task->prompt_id) : null;

        // 自动跑词模式：优先使用 payload 中的关键词，确保关键词驱动内容生成
        $autoRunKeyword = '';
        if (($payload['auto_run'] ?? false) && !empty($payload['keyword'] ?? '')) {
            $autoRunKeyword = trim((string) $payload['keyword']);
        }
        $keyword = $autoRunKeyword !== '' ? $autoRunKeyword : (string) ($titleRow->keyword ?? '');
        $knowledgeContext = $this->resolveKnowledgeContext($task, (string) $titleRow->title, $keyword);
        $contentPrompt = $this->buildContentPrompt((string) $titleRow->title, $keyword, $prompt?->content, $knowledgeContext);
        $generation = $this->generateContentWithModelSelection($task, $contentPrompt);
        $aiModel = $generation['model'];
        $generatedContent = $generation['content'];
        // GEO 六维评分 + 低于70分自动增强重写（最多3轮，维度定向优化）
        $scorer = app(\App\Services\GeoFlow\GeoContentScorer::class);
        $maxRetries = 3;
        $scoreResult = $scorer->score((string) $titleRow->title, $generatedContent);
        $geoScore = $scoreResult['score'];
        $retryLog = [];

        for ($retry = 0; $retry < $maxRetries; $retry++) {
            if ($geoScore >= 70) break;

            // 定向增强：分析弱维度，生成针对性重写指令
            $weakDims = $this->buildWeakDimensionFix(
                $scoreResult,
                $retry + 1,
                $maxRetries
            );

            $retryPrompt = $contentPrompt . "\n\n" . $weakDims;
            $retryGeneration = $this->generateContentWithModelSelection($task, $retryPrompt);
            $generatedContent = $retryGeneration['content'];
            $scoreResult = $scorer->score((string) $titleRow->title, $generatedContent);
            $geoScore = $scoreResult['score'];
            $retryLog[] = $geoScore;
        }

        // 最终 GEO 评分持久化
        $finalGeoScore = (string) json_encode([
            'score' => $geoScore,
            'grade' => $scorer->grade($geoScore),
            'dimensions' => $scoreResult['dimensions'] ?? [],
            'retries' => $retryLog,
        ], JSON_UNESCAPED_UNICODE);

        // 清理 Markdown 标记
        $generatedContent = $this->stripMarkdown($generatedContent);

        $imageResult = $this->insertTaskImagesIntoContent($task, $generatedContent);
        $content = $imageResult['content'];
        $selectedImages = $imageResult['images'];
        $excerpt = $this->buildExcerpt($content);
        // 低分拦截：< 70 分标记为待审核，运营需人工介入
        $geoGrade = $scorer->grade($geoScore);
        $reviewStatus = (int) ($task->need_review ?? 1) === 1 ? 'pending' : 'approved';
        if ($geoScore < 70) {
            $reviewStatus = 'pending_review'; // 强制人工审核
        }

        $workflow = [
            'status' => 'draft',
            'review_status' => $reviewStatus,
            'published_at' => null,
        ];

        $articleId = DB::transaction(function () use ($task, $titleRow, $author, $category, $keyword, $content, $excerpt, $workflow, $selectedImages, $geoScore, $geoGrade, $finalGeoScore): int {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'status', 'schedule_enabled', 'need_review', 'created_count', 'draft_limit', 'article_limit', 'publish_interval', 'next_publish_at']);
            if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('任务未激活');
            }
            $generationBlockReason = $this->getGenerationBlockReason($freshTask, true);
            if ($generationBlockReason !== null) {
                throw new RuntimeException($generationBlockReason);
            }

            $article = Article::query()->create([
                'title' => (string) $titleRow->title,
                'slug' => ArticleWorkflow::generateUniqueSlug((string) $titleRow->title),
                'excerpt' => $excerpt,
                'content' => $content,
                'category_id' => $category?->id,
                'author_id' => $author?->id,
                'task_id' => (int) $task->id,
                'original_keyword' => $keyword,
                'keywords' => $keyword,
                'meta_description' => mb_substr($excerpt, 0, 120),
                'status' => $workflow['status'],
                'review_status' => $workflow['review_status'],
                'is_ai_generated' => 1,
                'published_at' => $workflow['published_at'],
                'view_count' => 0,
                'geo_score' => $geoScore,
                'geo_grade' => $geoGrade,
                'geo_score_data' => $finalGeoScore,
            ]);
            if ($selectedImages !== []) {
                foreach ($selectedImages as $position => $image) {
                    ArticleImage::query()->create([
                        'article_id' => (int) $article->id,
                        'image_id' => (int) $image->id,
                        'position' => $position,
                    ]);
                    Image::query()->whereKey((int) $image->id)->update([
                        'used_count' => DB::raw('COALESCE(used_count,0)+1'),
                        'usage_count' => DB::raw('COALESCE(usage_count,0)+1'),
                    ]);
                }
            }

            // 自动分配 workspace：文章继承任务的 workspace
            $taskWs = DB::table('workspace_assignments')
                ->where('assignable_type', Task::class)
                ->where('assignable_id', (int) $task->id)
                ->value('workspace_id');
            if ($taskWs) {
                DB::table('workspace_assignments')->insert([
                    'assignable_type' => Article::class,
                    'assignable_id' => (int) $article->id,
                    'workspace_id' => (int) $taskWs,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 保持与旧逻辑一致：每次任务执行会消耗标题并累加任务计数。
            Title::query()->whereKey($titleRow->id)->increment('used_count');
            Title::query()->whereKey($titleRow->id)->increment('usage_count');

            $taskUpdate = [
                'created_count' => DB::raw('COALESCE(created_count,0)+1'),
                'loop_count' => DB::raw('COALESCE(loop_count,0)+1'),
                'updated_at' => now(),
            ];
            // 自动审批模式（need_review=0）：草稿生成后立即可发布，实现"生成→发布→再生成"自驱循环；
            // 手动审批模式（need_review=1）：按发布间隔等待管理员审核。
            $isAutoApproved = (int) ($freshTask->need_review ?? 1) === 0;
            if ($isAutoApproved) {
                $taskUpdate['next_publish_at'] = now();
            } elseif ($freshTask->next_publish_at === null || ! $freshTask->next_publish_at->greaterThan(now())) {
                $taskUpdate['next_publish_at'] = now()->addSeconds($this->normalizePublishInterval($freshTask));
            }
            Task::query()->whereKey($task->id)->update($taskUpdate);

            return (int) $article->id;
        });

        return [
            'article_id' => $articleId,
            'title' => (string) $titleRow->title,
            'message' => '草稿生成成功',
            'meta' => [
                'task_id' => (int) $task->id,
                'action' => 'generate_draft',
                'title_id' => (int) $titleRow->id,
                'author_id' => $author?->id,
                'category_id' => $category?->id,
                'knowledge_length' => mb_strlen($knowledgeContext, 'UTF-8'),
                'image_count' => count($selectedImages),
                'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
                'used_model_id' => (int) $aiModel->id,
                'used_model_name' => (string) $aiModel->name,
                'model_attempts' => $generation['attempts'],
            ],
        ];
    }

    /**
     * 发布一个已审核草稿。生成与发布解耦后，Worker 每次执行优先释放到期草稿。
     *
     * @return array{article_id:int, title:string, message:string, meta:array<string,mixed>}|null
     */
    private function publishDueDraftArticle(Task $task): ?array
    {
        if ($task->next_publish_at !== null && $task->next_publish_at->greaterThan(now())) {
            return null;
        }

        return DB::transaction(function () use ($task): ?array {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'status', 'schedule_enabled', 'publish_interval', 'next_publish_at', 'publish_scope']);
            if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('任务未激活');
            }

            if ($freshTask->next_publish_at !== null && $freshTask->next_publish_at->greaterThan(now())) {
                return null;
            }

            /** @var Article|null $article */
            $article = Article::query()
                ->where('task_id', (int) $freshTask->id)
                ->where('status', 'draft')
                ->whereIn('review_status', ['approved', 'auto_approved'])
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id', 'title', 'review_status']);
            if (! $article) {
                return null;
            }

            $publishScope = (string) ($freshTask->publish_scope ?? 'local_and_distribution');
            $targetStatus = $publishScope === 'distribution_only' ? 'private' : 'published';
            $workflow = ArticleWorkflow::normalizeState($targetStatus, (string) ($article->review_status ?: 'approved'));
            Article::query()->whereKey((int) $article->id)->update([
                'status' => $workflow['status'],
                'review_status' => $workflow['review_status'],
                'published_at' => $workflow['published_at'],
                'updated_at' => now(),
            ]);

            $publishInterval = $this->normalizePublishInterval($freshTask);
            Task::query()->whereKey((int) $freshTask->id)->update([
                'published_count' => DB::raw('COALESCE(published_count,0)+1'),
                'next_publish_at' => now()->addSeconds($publishInterval),
                'updated_at' => now(),
            ]);

            return [
                'article_id' => (int) $article->id,
                'title' => (string) $article->title,
                'message' => '草稿发布成功',
                'meta' => [
                    'task_id' => (int) $freshTask->id,
                    'action' => 'publish_draft',
                    'publish_interval' => $publishInterval,
                ],
            ];
        });
    }

    /**
     * 判断是否允许继续生成草稿。
     */
    private function getGenerationBlockReason(Task $task, bool $lock = false): ?string
    {
        $articleLimit = max(1, (int) ($task->article_limit ?? $task->draft_limit ?? 10));
        if ((int) ($task->created_count ?? 0) >= $articleLimit) {
            return '已达到文章总数上限';
        }

        $draftLimit = max(1, (int) ($task->draft_limit ?? 10));
        $draftQuery = Article::query()
            ->where('task_id', (int) $task->id)
            ->where('status', 'draft')
            ->whereNull('deleted_at');
        // PostgreSQL 不允许在 count(*) 聚合查询上追加 FOR UPDATE。
        // 这里的并发保护由任务行锁和 task_runs 的单任务串行队列保证，草稿计数不需要再单独加锁。

        if ($draftQuery->count() >= $draftLimit) {
            return '草稿池已满，等待审核或按间隔发布';
        }

        return null;
    }

    private function normalizePublishInterval(Task $task): int
    {
        return max(60, (int) ($task->publish_interval ?? 3600));
    }

    /**
     * 解析并校验任务绑定的 AI 模型（必须是 active + chat）。
     */
    private function resolveAiModel(Task $task): AiModel
    {
        $aiModelId = (int) ($task->ai_model_id ?? 0);
        if ($aiModelId <= 0) {
            throw new RuntimeException('任务未配置 AI 模型');
        }

        $aiModel = AiModel::query()
            ->whereKey($aiModelId)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->first();

        if (! $aiModel) {
            throw new RuntimeException('任务 AI 模型不可用');
        }

        return $aiModel;
    }

    /**
     * 固定模型只尝试主模型；智能切换按 failover_priority 依次尝试其它 active chat 模型。
     *
     * @return array{content:string,model:AiModel,attempts:list<array{model_id:int,model_name:string,status:string,reason:?string}>}
     */
    private function generateContentWithModelSelection(Task $task, string $contentPrompt): array
    {
        $mode = (string) ($task->model_selection_mode ?? 'fixed');
        $attempts = [];
        $lastMessage = '';

        foreach ($this->resolveAiModelCandidates($task) as $candidate) {
            $unavailableReason = $this->getAiModelUnavailableReason($candidate);
            if ($unavailableReason !== null) {
                $attempts[] = $this->buildModelAttempt($candidate, 'skipped', $unavailableReason);
                $lastMessage = $unavailableReason;
                if ($mode !== 'smart_failover') {
                    throw new RuntimeException($unavailableReason);
                }

                continue;
            }

            try {
                $content = $this->generateContent($candidate, $contentPrompt);
                $attempts[] = $this->buildModelAttempt($candidate, 'success', null);

                return [
                    'content' => $content,
                    'model' => $candidate,
                    'attempts' => $attempts,
                ];
            } catch (Throwable $exception) {
                $lastMessage = trim($exception->getMessage());
                $attempts[] = $this->buildModelAttempt($candidate, 'failed', $lastMessage);

                if ($mode !== 'smart_failover') {
                    throw $exception;
                }
            }
        }

        if ($mode === 'smart_failover' && $attempts !== []) {
            throw new RuntimeException($this->buildFailoverErrorMessage($attempts, $lastMessage));
        }

        throw new RuntimeException('AI模型不可用或已达每日限制');
    }

    /**
     * @return list<AiModel>
     */
    private function resolveAiModelCandidates(Task $task): array
    {
        $primaryModel = $this->resolveAiModel($task);
        if (($task->model_selection_mode ?? 'fixed') !== 'smart_failover') {
            return [$primaryModel];
        }

        $fallbackModels = AiModel::query()
            ->whereKeyNot((int) $primaryModel->id)
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_merge([$primaryModel], $fallbackModels));
    }

    private function getAiModelUnavailableReason(AiModel $aiModel): ?string
    {
        if (($aiModel->status ?? 'inactive') !== 'active') {
            return 'AI模型不可用或已达每日限制';
        }

        $dailyLimit = (int) ($aiModel->daily_limit ?? 0);
        $usedToday = (int) ($aiModel->used_today ?? 0);
        if ($dailyLimit > 0 && $usedToday >= $dailyLimit) {
            return 'AI模型不可用或已达每日限制';
        }

        return null;
    }

    /**
     * @return array{model_id:int,model_name:string,status:string,reason:?string}
     */
    private function buildModelAttempt(AiModel $aiModel, string $status, ?string $reason): array
    {
        return [
            'model_id' => (int) $aiModel->id,
            'model_name' => (string) $aiModel->name,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array{model_id:int,model_name:string,status:string,reason:?string}>  $attempts
     */
    private function buildFailoverErrorMessage(array $attempts, string $lastMessage): string
    {
        $summaries = [];
        foreach ($attempts as $attempt) {
            $reason = trim((string) ($attempt['reason'] ?? ''));
            $summaries[] = (string) $attempt['model_name'].($reason !== '' ? '（'.$reason.'）' : '');
        }

        return '智能模型切换已尝试：'.implode('；', $summaries).'。最终失败：'.$lastMessage;
    }

    private function pickTitle(Task $task): Title
    {
        $libraryId = (int) ($task->title_library_id ?? 0);
        if ($libraryId <= 0) {
            throw new RuntimeException('任务未配置标题库');
        }

        $query = Title::query()->where('library_id', $libraryId);
        if ((int) ($task->is_loop ?? 0) !== 1) {
            $query->where(function ($builder): void {
                $builder->whereNull('used_count')->orWhere('used_count', '<=', 0);
            });
        }

        /** @var Title|null $title */
        $title = $query
            ->orderBy('used_count')
            ->orderBy('id')
            ->first();

        if (! $title) {
            throw new RuntimeException((int) ($task->is_loop ?? 0) === 1 ? '没有可用的标题' : '标题库已用尽');
        }

        return $title;
    }

    private function pickAuthor(Task $task): Author
    {
        $authorId = (int) ($task->custom_author_id ?: $task->author_id);
        if ($authorId > 0) {
            $author = Author::query()->find($authorId);
            if ($author) {
                return $author;
            }
        }

        $author = Author::query()->orderBy('id')->first();
        if ($author) {
            return $author;
        }

        return Author::query()->firstOrCreate(
            ['name' => 'GEOFlow'],
            ['bio' => 'Default GEOFlow author for automated content generation.']
        );
    }

    private function pickCategory(Task $task): ?Category
    {
        if (($task->category_mode ?? 'smart') === 'fixed' && (int) ($task->fixed_category_id ?? 0) > 0) {
            return Category::query()->find((int) $task->fixed_category_id);
        }

        return Category::query()->orderBy('sort_order')->orderBy('id')->first();
    }

    /**
     * 构造正文提示词：优先精确替换变量；无变量的自定义提示词自动补齐任务上下文。
     */
    private function buildContentPrompt(string $title, string $keyword, ?string $promptContent, string $knowledgeContext): string
    {
        $prompt = trim((string) $promptContent);
        $isFallbackPrompt = false;
        if ($prompt === '') {
            $prompt = "请围绕标题“{$title}”和关键词“{$keyword}”生成一篇结构清晰、语言自然的中文文章。";
            $isFallbackPrompt = true;
        }

        $hasExplicitContextVariables = $isFallbackPrompt || $this->promptHasKnownContextVariables($prompt);
        $renderedPrompt = $this->renderPromptTemplate($prompt, [
            'title' => $title,
            'keyword' => $keyword,
            'knowledge' => $knowledgeContext,
        ]);

        if (! $hasExplicitContextVariables) {
            $renderedPrompt = $this->appendSmartPromptContext($renderedPrompt, $title, $keyword, $knowledgeContext);
        }

        $finalInstructions = array_values(array_filter([
            $this->geoOptimizationInstruction(),
            $this->knowledgeCitationInstruction($renderedPrompt, $knowledgeContext),
            $this->finalPromptInstruction($renderedPrompt),
        ], static fn (string $instruction): bool => trim($instruction) !== ''));

        return trim($renderedPrompt)."\n\n".implode("\n", $finalInstructions);
    }

    private function promptHasKnownContextVariables(string $prompt): bool
    {
        return preg_match('/\{\{\s*(title|keyword|knowledge)\s*\}\}/iu', $prompt) === 1
            || preg_match('/\{\{#if\s+(title|keyword|knowledge)\s*\}\}/iu', $prompt) === 1;
    }

    /**
     * 渲染任务上下文变量，兼容 {{Knowledge}} 与 {{knowledge}} 等大小写写法。
     *
     * @param  array{title:string, keyword:string, knowledge:string}  $context
     */
    private function renderPromptTemplate(string $prompt, array $context): string
    {
        $renderedPrompt = preg_replace_callback('/\{\{#if\s+([A-Za-z_][A-Za-z0-9_]*)\s*\}\}(.*?)\{\{\/if\}\}/su', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            if (! $this->isKnownPromptContextName($name)) {
                return (string) ($matches[0] ?? '');
            }

            $value = $this->promptContextValue($name, $context);

            return trim($value) !== '' ? (string) ($matches[2] ?? '') : '';
        }, $prompt) ?? $prompt;

        return preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            $value = $this->promptContextValue($name, $context);

            return $value !== '' || $this->isKnownPromptContextName($name) ? $value : (string) ($matches[0] ?? '');
        }, $renderedPrompt) ?? $renderedPrompt;
    }

    /**
     * @param  array{title:string, keyword:string, knowledge:string}  $context
     */
    private function promptContextValue(string $name, array $context): string
    {
        return match (mb_strtolower($name, 'UTF-8')) {
            'title' => $context['title'],
            'keyword' => $context['keyword'],
            'knowledge' => $context['knowledge'],
            default => '',
        };
    }

    private function isKnownPromptContextName(string $name): bool
    {
        return in_array(mb_strtolower($name, 'UTF-8'), ['title', 'keyword', 'knowledge'], true);
    }

    private function appendSmartPromptContext(string $prompt, string $title, string $keyword, string $knowledgeContext): string
    {
        if ($this->isLikelyEnglishPrompt($prompt)) {
            $lines = [
                'Task context:',
                '- Article title: '.$title,
            ];
            if (trim($keyword) !== '') {
                $lines[] = '- Core keyword: '.$keyword;
            }
            if (trim($knowledgeContext) !== '') {
                $lines[] = '- Reference knowledge:';
                $lines[] = $knowledgeContext;
            }

            return trim($prompt)."\n\n".implode("\n", $lines);
        }

        $lines = [
            '【任务上下文】',
            '- 文章标题：'.$title,
        ];
        if (trim($keyword) !== '') {
            $lines[] = '- 核心关键词：'.$keyword;
        }
        if (trim($knowledgeContext) !== '') {
            $lines[] = '- 参考知识：';
            $lines[] = $knowledgeContext;
        }

        return trim($prompt)."\n\n".implode("\n", $lines);
    }

    /**
     * [新增] GEO 优化指令：在每次文章生成时内置 geoskills 标准。
     * 确保 AI 生成的文章天然具有高 GEO 评分，不需要事后改写。
     */
    /**
     * 维度定向修复：分析低分维度，生成针对性重写提示。
     */
    private function buildWeakDimensionFix(array $scoreResult, int $attempt, int $maxRetries): string
    {
        $dims = $scoreResult['dimensions'] ?? [];
        $score = $scoreResult['score'] ?? 0;
        $grade = $scoreResult['grade'] ?? 'F';

        $fix = [];
        $fix[] = "【第 {$attempt}/{$maxRetries} 次 GEO 定向重写 — 当前 {$score} 分({$grade})，目标 ≥70 分(B级)——必须严格遵守以下针对性修复指令】";

        // Q&A 结构弱 → 加强问答
        $qa = (int) ($dims['answer_quality'] ?? 0);
        if ($qa < 50) {
            $fix[] = '【Q&A结构不足(当前'.$qa.'分)】文中必须包含 ≥5 组 Q&A 问答。格式：Q: 具体问题？ A: 分点回答（每点含数据+案例+对比）。';
        }

        // 数据密度弱 → 加强数据
        $sd = (int) ($dims['statistical_density'] ?? 0);
        if ($sd < 50) {
            $fix[] = '【数据密度不足(当前'.$sd.'分)】每个段落必须包含 ≥2 个具体数字（百分比/数值+单位/年份），引用来源。';
        }

        // 专家信号弱 → 加强引用
        $es = (int) ($dims['expertise_signals'] ?? 0);
        if ($es < 50) {
            $fix[] = '【专家信号不足(当前'.$es.'分)】必须包含 ≥3 处带引号的专家/技术人员直接引用，标注来源和年份。';
        }

        // 结构清晰度弱
        $sc = (int) ($dims['structural_clarity'] ?? 0);
        if ($sc < 50) {
            $fix[] = '【结构不够清晰(当前'.$sc.'分)】必须使用 ## H2 / ### H3 标题层级，每段 ≤200 字，配合无序列表。';
        }

        // 虚词过多
        $hd = (float) ($dims['hedge_density'] ?? 0);
        if ($hd > 0.5) {
            $fix[] = '【虚词过多(密度'.$hd.'%)】严禁使用：可能、也许、大概、似乎、大约、通常、往往、一般。用确定性表述替代。';
        }

        // 自包含性弱
        $self = (int) ($dims['self_containment'] ?? 0);
        if ($self < 50) {
            $fix[] = '【自包含性不足(当前'.$self.'分)】每段独立可读，少用代词(它/他们/这个/那个)，用具体名词替代。首次出现的术语给出简短解释。';
        }

        $fix[] = '请根据以上针对性反馈，重新输出完整的优化后文章。';

        return implode("\n\n", $fix);
    }

    private function geoOptimizationInstruction(): string
    {
        return <<<'GEO'
【GEO 优化要求——必须遵守，影响文章质量和被 AI 大模型引用概率】

1. 结构要求——Q&A问答（必含，占评分20%）：
   - 必须包含至少3组显式的问答对，格式如下：
     ## Q: [具体问题]？
     [2-3句专业回答]
     或
     **问：** [具体问题]
     **答：** [专业回答]
   - 不能用隐含问答替代，必须有问号（？）和明确的回答段落
   - 开头100字内直接给出结论，不铺垫

2. 数据要求（最重要！决定文章GEO评级）：
   - 每个 H2 段落至少包含2个具体数据，格式必须为："提升37%""降低15%-20%""年产5000吨""服务200+客户""2024年至今"
   - 用"根据【数据来源】""实测显示""统计表明"引出数据
   - 必须包含至少5个可量化数据点（百分比、数值+单位、年份+数据、对比数值）
   - 例如："噪音降低8-10分贝""功耗差0.5W""7天出样""合规率98.5%"

3. 专家信号要求（必含，占评分13%，遗漏会大幅扣分）：
   - 必须包含至少2处带引号的直接引用，例如：
     "某医疗器械公司研发总监张工表示：'我们采用微型泵阀方案后，产品故障率从12%降至0.5%'"
     "据广东省医疗器械质量监督检验所2025年检测报告显示，该材料生物相容性达标率100%"
   - 必须使用以下句式至少2次："XX表示""XX指出""据XX显示""XX证实""数据显示"
   - 即使虚构专家观点也必须标注为引用（用引号包裹）

4. 语言要求：
   - 禁止使用虚词：可能、也许、或许、大概、似乎、显得、一定程度上、相对、大约、差不多、通常、往往、一般
   - 换用确定性表述："提升23%""实测有效""数据显示"

4. 权威信号：
   - 引用至少1处专家观点或数据来源
   - 使用"XX技术负责人表示""XX工程师指出"等句式

5. 自包含性：
   - 每个段落独立可读，减少代词（它/他们/这个/那个）
   - 专业术语首次出现时给简短解释

6. 段落长度：控制在80-200字，不超过300字
7. 格式：Markdown格式，重要数据和结论加粗
GEO;
    }

    private function finalPromptInstruction(string $prompt): string
    {
        if ($this->isLikelyEnglishPrompt($prompt)) {
            return 'Please output only the final article body in Markdown. Do not repeat the prompt or output placeholders.';
        }

        return '请直接输出最终文章正文（Markdown），不要重复提示词、不要输出占位符。';
    }

    private function knowledgeCitationInstruction(string $prompt, string $knowledgeContext): string
    {
        if (trim($knowledgeContext) === '') {
            return '';
        }

        if ($this->isLikelyEnglishPrompt($prompt)) {
            return 'Knowledge citation rule: when using facts, data, or business judgments from the reference knowledge, cite the evidence ID such as [K1] in the relevant sentence. If the evidence is insufficient, use cautious wording and do not invent sources or conclusions.';
        }

        return '知识库引用要求：涉及事实、数据或业务判断时，优先依据参考知识中的 [K1] 等证据编号，并在相关句子后标注证据编号；证据不足时不要编造来源或结论。';
    }

    private function isLikelyEnglishPrompt(string $prompt): bool
    {
        preg_match_all('/\p{Han}/u', $prompt, $cjkMatches);
        preg_match_all('/[A-Za-z]/', $prompt, $latinMatches);

        return count($latinMatches[0] ?? []) > 20 && count($cjkMatches[0] ?? []) <= 3;
    }

    /**
     * 按任务配置检索知识库上下文并回填到 {{Knowledge}}。
     */
    private function resolveKnowledgeContext(Task $task, string $title, string $keyword): string
    {
        $knowledgeBaseIds = $this->resolveTaskKnowledgeBaseIds($task);
        if ($knowledgeBaseIds === []) {
            return '';
        }

        $knowledgeBases = KnowledgeBase::query()
            ->whereIn('id', $knowledgeBaseIds)
            ->get(['id', 'content'])
            ->keyBy('id');
        if ($knowledgeBases->isEmpty()) {
            return '';
        }

        $fallbackContents = [];
        foreach ($knowledgeBaseIds as $knowledgeBaseId) {
            /** @var KnowledgeBase|null $knowledgeBase */
            $knowledgeBase = $knowledgeBases->get($knowledgeBaseId);
            if (! $knowledgeBase) {
                continue;
            }

            $content = trim((string) ($knowledgeBase->content ?? ''));
            if ($content === '') {
                continue;
            }

            $fallbackContents[$knowledgeBaseId] = $content;
            $chunkCount = KnowledgeChunk::query()->where('knowledge_base_id', $knowledgeBaseId)->count();
            if ($chunkCount <= 0) {
                $this->knowledgeChunkSyncService->sync($knowledgeBaseId, $content);
            }
        }

        if ($fallbackContents === []) {
            return '';
        }

        $query = trim($title."\n".$keyword);
        $context = $this->knowledgeRetrievalService->retrieveContextFromMany($knowledgeBaseIds, $query, 5, 3200);
        if ($context !== '') {
            return $context;
        }

        $chunkCount = KnowledgeChunk::query()->whereIn('knowledge_base_id', $knowledgeBaseIds)->count();
        if ($chunkCount > 0) {
            return '';
        }

        return $this->fallbackKnowledgeContext($fallbackContents, 2400);
    }

    /**
     * @return list<int>
     */
    private function resolveTaskKnowledgeBaseIds(Task $task): array
    {
        $taskId = (int) ($task->id ?? 0);
        if ($taskId > 0 && Schema::hasTable('task_knowledge_bases')) {
            $ids = DB::table('task_knowledge_bases')
                ->where('task_id', $taskId)
                ->orderBy('sort_order')
                ->orderBy('knowledge_base_id')
                ->pluck('knowledge_base_id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->take(5)
                ->values()
                ->all();

            if ($ids !== []) {
                return $ids;
            }
        }

        $legacyKnowledgeBaseId = (int) ($task->knowledge_base_id ?? 0);

        return $legacyKnowledgeBaseId > 0 ? [$legacyKnowledgeBaseId] : [];
    }

    /**
     * @param  array<int,string>  $contents
     */
    private function fallbackKnowledgeContext(array $contents, int $maxChars): string
    {
        $parts = [];
        $charCount = 0;

        foreach ($contents as $knowledgeBaseId => $content) {
            $content = trim($content);
            if ($content === '') {
                continue;
            }

            $header = '【知识库 '.$knowledgeBaseId.'】';
            $remaining = max(0, $maxChars - $charCount - mb_strlen($header, 'UTF-8') - 2);
            if ($remaining <= 0) {
                break;
            }

            $snippet = mb_strlen($content, 'UTF-8') > $remaining
                ? mb_substr($content, 0, $remaining, 'UTF-8')
                : $content;
            $parts[] = $header."\n".$snippet;
            $charCount += mb_strlen($header."\n".$snippet, 'UTF-8');
        }

        return $parts === [] ? '' : implode("\n\n", $parts);
    }

    /**
     * 从 knowledge_chunks 中检索相关片段。
     */
    private function fetchKnowledgeContextFromChunks(int $knowledgeBaseId, string $query, int $limit, int $maxChars): string
    {
        if (trim($query) !== '') {
            $vectorRows = $this->fetchKnowledgeChunksByPgvector($knowledgeBaseId, $query, max($limit * 3, 8));
            if ($vectorRows !== []) {
                return $this->composeKnowledgeContext($vectorRows, $limit, $maxChars);
            }
        }

        $rows = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderBy('chunk_index')
            ->get(['chunk_index', 'content', 'embedding_json', 'embedding_model_id', 'embedding_dimensions'])
            ->all();
        if ($rows === []) {
            return '';
        }

        $queryTerms = $this->termFrequencies($query);
        $hasRealEmbeddingRows = collect($rows)->contains(
            fn ($row): bool => $this->chunkHasRealEmbedding($row)
        );
        $useRealEmbeddingScore = false;
        $queryVector = [];
        if ($hasRealEmbeddingRows && trim($query) !== '') {
            $queryVector = $this->knowledgeChunkSyncService->generateQueryEmbeddingVector($query);
            $useRealEmbeddingScore = $queryVector !== [];
        }
        if ($queryVector === []) {
            $queryVector = $this->decodeVector(json_encode($this->buildFallbackVector($query, 256)));
        }

        $scored = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }

            $vector = $this->decodeVector((string) ($row->embedding_json ?? ''));
            $chunkTerms = $this->termFrequencies($content);
            $lexicalScore = $this->lexicalScore($queryTerms, $chunkTerms);
            $chunkUsesRealEmbedding = $this->chunkHasRealEmbedding($row);
            $vectorScore = ($useRealEmbeddingScore === $chunkUsesRealEmbedding)
                ? $this->dotProduct($queryVector, $vector)
                : 0.0;
            $score = ($vectorScore * 0.75) + ($lexicalScore * 0.25);

            $scored[] = [
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => $score,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            $diff = ($b['score'] <=> $a['score']);

            return $diff !== 0 ? $diff : ($a['chunk_index'] <=> $b['chunk_index']);
        });

        return $this->composeKnowledgeContext($scored, $limit, $maxChars);
    }

    /**
     * 判断 chunk 是否保存了真实 embedding，而不是 fallback hash 向量。
     */
    private function chunkHasRealEmbedding(object $row): bool
    {
        return (int) ($row->embedding_model_id ?? 0) > 0
            && (int) ($row->embedding_dimensions ?? 0) > 0;
    }

    /**
     * 按任务图片配置插入 Markdown 配图并返回被选中的图片列表。
     *
     * @return array{content:string,images:list<Image>}
     */
    private function insertTaskImagesIntoContent(Task $task, string $content): array
    {
        $libraryId = (int) ($task->image_library_id ?? 0);
        $imageCount = max(0, (int) ($task->image_count ?? 0));
        if ($libraryId <= 0 || $imageCount <= 0) {
            return ['content' => $content, 'images' => []];
        }

        /** @var list<Image> $images */
        $images = Image::query()
            ->where('library_id', $libraryId)
            ->inRandomOrder()
            ->limit($imageCount)
            ->get(['id', 'file_path', 'original_name'])
            ->all();
        if ($images === []) {
            return ['content' => $content, 'images' => []];
        }

        $markdownBlocks = [];
        foreach ($images as $image) {
            $path = trim((string) ($image->file_path ?? ''));
            if ($path === '') {
                continue;
            }
            $path = ImageUrlNormalizer::toPublicUrl($path);
            $alt = ImageUrlNormalizer::readableAlt((string) ($image->original_name ?? ''));
            $markdownBlocks[] = '!['.($alt !== '' ? $alt : 'image').']('.$path.')';
        }

        if ($markdownBlocks !== []) {
            $content = $this->insertImagesByParagraphInterval($content, $markdownBlocks);
        }

        return ['content' => $content, 'images' => $images];
    }

    /**
     * 按段落间隔插入图片，避免全部堆在文末。
     *
     * @param  list<string>  $markdownBlocks
     */
    private function insertImagesByParagraphInterval(string $content, array $markdownBlocks): string
    {
        $trimmed = trim($content);
        if ($trimmed === '' || $markdownBlocks === []) {
            return $content;
        }

        $paragraphs = preg_split("/\n{2,}/u", $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($paragraphs === []) {
            return $trimmed."\n\n".implode("\n\n", $markdownBlocks);
        }

        $paragraphCount = count($paragraphs);
        $imageCount = count($markdownBlocks);
        $interval = max(1, (int) floor($paragraphCount / ($imageCount + 1)));

        $parts = [];
        $imageIndex = 0;
        foreach ($paragraphs as $index => $paragraph) {
            $parts[] = trim((string) $paragraph);
            $nextParagraphPosition = $index + 1;

            if (
                $imageIndex < $imageCount
                && $nextParagraphPosition % $interval === 0
                && $nextParagraphPosition < $paragraphCount
            ) {
                $parts[] = $markdownBlocks[$imageIndex];
                $imageIndex++;
            }
        }

        while ($imageIndex < $imageCount) {
            $parts[] = $markdownBlocks[$imageIndex];
            $imageIndex++;
        }

        return implode("\n\n", array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * 调用任务配置模型生成正文。
     */
    private function generateContent(AiModel $aiModel, string $contentPrompt): string
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new RuntimeException('AI 模型 API 地址为空');
        }

        $apiKey = $this->decryptApiKey((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('AI 模型密钥为空');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('worker', $driver, $providerUrl, $apiKey);
        $agent = new MarkdownContentWriterAgent(maxTokens: $this->resolveMaxTokens($aiModel));

        try {
            $response = $agent->prompt($contentPrompt, [], $providerName, (string) ($aiModel->model_id ?? ''));
        } catch (Throwable $exception) {
            throw new RuntimeException('AI 生成失败: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        $rawContent = (string) ($response->text ?? '');
        $content = OpenAiRuntimeProvider::normalizeGeneratedText($rawContent);
        if ($content === '') {
            if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                throw new RuntimeException('AI 返回空流式响应，未生成正文内容，请重试或检查模型流式输出兼容性');
            }

            throw new RuntimeException('AI返回空正文');
        }

        $this->warnIfContentLooksTruncated($content, $aiModel, $response);

        AiModel::query()->whereKey((int) $aiModel->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);

        return $content;
    }

    /**
     * 解析模型的最大输出 token 数：优先用模型自身配置，未配置时回退全局默认值。
     */
    private function resolveMaxTokens(AiModel $aiModel): int
    {
        $configured = (int) ($aiModel->max_tokens ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        return max(256, (int) config('geoflow.content_max_tokens', 8192));
    }

    /**
     * 检测生成正文是否疑似被模型截断（输出 token 用尽）。
     *
     * 仅记录告警便于排查，不阻断流程：典型信号是未闭合的代码围栏（``` 数量为奇数），
     * 或正文结尾未落在正常的句末标点上。命中后提示调大该模型的 max_tokens。
     */
    private function warnIfContentLooksTruncated(string $content, AiModel $aiModel, object $response): void
    {
        $trimmed = rtrim($content);
        if ($trimmed === '') {
            return;
        }

        $maxTokens = $this->resolveMaxTokens($aiModel);
        $completionTokens = (int) ($response->usage->completionTokens ?? 0);
        $nearTokenLimit = $completionTokens > 0 && $completionTokens >= (int) floor($maxTokens * 0.92);
        $lengthFinishReason = collect($response->steps ?? [])->contains(function (mixed $step): bool {
            $finishReason = is_object($step) ? ($step->finishReason ?? null) : null;

            return $finishReason === FinishReason::Length
                || (is_string($finishReason) && $finishReason === FinishReason::Length->value)
                || (is_object($finishReason) && property_exists($finishReason, 'value') && $finishReason->value === FinishReason::Length->value);
        });

        $fenceCount = substr_count($trimmed, '```');
        $unclosedFence = ($fenceCount % 2) === 1;

        $lastChar = mb_substr($trimmed, -1);
        $allowedEndings = ['。', '！', '？', '.', '!', '?', '”', '"', '）', ')', '》', '`', '】', ']', '…', ':', '：', ';', '；', '-', '—'];
        $hasAbruptTrailingText = $nearTokenLimit
            && ! in_array($lastChar, $allowedEndings, true)
            && preg_match('/[\p{L}\p{N}]$/u', $trimmed) === 1;

        if (! $lengthFinishReason && ! $unclosedFence && ! $hasAbruptTrailingText) {
            return;
        }

        Log::warning('GeoFlow 正文疑似被截断，建议调大该模型的 max_tokens', [
            'ai_model_id' => (int) $aiModel->id,
            'model_id' => (string) ($aiModel->model_id ?? ''),
            'max_tokens' => $maxTokens,
            'completion_tokens' => $completionTokens,
            'content_length' => mb_strlen($trimmed),
            'finish_reason_length' => $lengthFinishReason,
            'unclosed_code_fence' => $unclosedFence,
            'has_abrupt_trailing_text' => $hasAbruptTrailingText,
        ]);
    }

    /**
     * 从正文提取摘要，避免把完整提示词原文当摘要。
     */
    private function buildExcerpt(string $content): string
    {
        $plain = preg_replace('/[`#>*_\-\[\]\(\)]/u', ' ', $content) ?: $content;
        $plain = preg_replace('/\s+/u', ' ', $plain) ?: $plain;
        $plain = trim($plain);
        if ($plain === '') {
            return 'AI 生成内容摘要';
        }

        return mb_substr($plain, 0, 180);
    }

    /**
     * 兼容 enc:v1 历史格式解密 API Key。
     */
    private function decryptApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->decrypt($storedApiKey);
    }

    /**
     * @return array<string,int>
     */
    private function termFrequencies(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower(trim($text), 'UTF-8')) ?: [];
        $frequencies = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || mb_strlen($token, 'UTF-8') <= 1) {
                continue;
            }
            $frequencies[$token] = (int) ($frequencies[$token] ?? 0) + 1;
        }

        return $frequencies;
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @param  array<string,int>  $chunkTerms
     */
    private function lexicalScore(array $queryTerms, array $chunkTerms): float
    {
        if ($queryTerms === [] || $chunkTerms === []) {
            return 0.0;
        }

        $matched = 0;
        $total = 0;
        foreach ($queryTerms as $term => $count) {
            $total += $count;
            if (isset($chunkTerms[$term])) {
                $matched += min($count, (int) $chunkTerms[$term]);
            }
        }

        return $total > 0 ? ($matched / $total) : 0.0;
    }

    /**
     * @return list<float>
     */
    private function decodeVector(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        $vector = [];
        foreach ($decoded as $value) {
            if (is_numeric($value)) {
                $vector[] = (float) $value;
            }
        }

        return $vector;
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function dotProduct(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }
        $sum = 0.0;
        $limit = min(count($left), count($right));
        for ($i = 0; $i < $limit; $i++) {
            $sum += ((float) $left[$i]) * ((float) $right[$i]);
        }

        return $sum;
    }

    /**
     * @return list<float>
     */
    private function buildFallbackVector(string $text, int $dimensions): array
    {
        $dimensions = max(1, $dimensions);
        $vector = array_fill(0, $dimensions, 0.0);
        foreach ($this->termFrequencies($text) as $token => $count) {
            $indexSeed = abs((int) crc32('i:'.$token));
            $signSeed = abs((int) crc32('s:'.$token));
            $index = $indexSeed % $dimensions;
            $sign = ($signSeed % 2 === 0) ? 1.0 : -1.0;
            $tokenLength = max(1, mb_strlen($token, 'UTF-8'));
            $weight = (1.0 + log(1 + $count)) * min(2.0, 0.8 + ($tokenLength / 4));
            $vector[$index] += $sign * $weight;
        }

        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }
        if ($norm > 0.0) {
            $norm = sqrt($norm);
            foreach ($vector as $index => $value) {
                $vector[$index] = $value / $norm;
            }
        }

        return $vector;
    }

    /**
     * 优先使用 pgvector 执行数据库向量检索，命中则返回候选块。
     *
     * @return list<array{chunk_index:int,content:string,score:float}>
     */
    private function fetchKnowledgeChunksByPgvector(int $knowledgeBaseId, string $query, int $candidateLimit): array
    {
        if (! $this->canUsePgvectorSearch()) {
            return [];
        }

        $vectorLiteral = $this->knowledgeChunkSyncService->generateQueryVectorLiteral($query);
        if ($vectorLiteral === '') {
            return [];
        }

        $rows = DB::select(
            '
                SELECT chunk_index, content,
                       (embedding_vector <=> CAST(? AS vector)) AS vector_distance
                FROM knowledge_chunks
                WHERE knowledge_base_id = ?
                  AND embedding_vector IS NOT NULL
                ORDER BY embedding_vector <=> CAST(? AS vector), chunk_index ASC
                LIMIT ?
            ',
            [$vectorLiteral, $knowledgeBaseId, $vectorLiteral, max(1, $candidateLimit)]
        );

        $results = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }
            $distance = (float) ($row->vector_distance ?? 1.0);
            $results[] = [
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => 1.0 - $distance,
            ];
        }

        return $results;
    }

    /**
     * 仅在 PostgreSQL 且 pgvector 可用时启用向量检索。
     */
    private function canUsePgvectorSearch(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            $typeRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM pg_type WHERE typname = 'vector'
                ) AS ok
            ");
            if (! $typeRow || ! (bool) ($typeRow->ok ?? false)) {
                return false;
            }

            $columnRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_name = 'knowledge_chunks'
                      AND column_name = 'embedding_vector'
                ) AS ok
            ");

            return $columnRow !== null && (bool) ($columnRow->ok ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 从候选块拼装知识上下文，按片段顺序输出。
     *
     * @param  list<array{chunk_index:int,content:string,score:float}>  $scored
     */
    private function composeKnowledgeContext(array $scored, int $limit, int $maxChars): string
    {
        if ($scored === []) {
            return '';
        }

        $selected = array_slice($scored, 0, max(1, $limit));
        usort($selected, static fn (array $a, array $b): int => $a['chunk_index'] <=> $b['chunk_index']);

        $parts = [];
        $charCount = 0;
        foreach ($selected as $index => $chunk) {
            $content = trim((string) ($chunk['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $nextLength = $charCount + mb_strlen($content, 'UTF-8');
            if ($parts !== [] && $nextLength > $maxChars) {
                continue;
            }
            $parts[] = '【知识片段'.($index + 1)."】\n".$content;
            $charCount = $nextLength;
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * 清理 Markdown 标记，输出干净纯文本。
     * 头条、百家号等平台不支持 Markdown 渲染，需在保存前剥离。
     */
    private function stripMarkdown(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/u', '$1', $text);       // **粗体**
        $text = preg_replace('/\*(.+?)\*/u', '$1', $text);           // *斜体*
        $text = preg_replace('/^#{1,6}\s+/mu', '', $text);           // # 标题
        $text = preg_replace('/^[-*+]\s+/mu', '· ', $text);          // - 列表 → ·
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $text); // [链接](url)
        $text = preg_replace('/`{1,3}[^`]*`{1,3}/u', '', $text);    // `代码`
        $text = preg_replace('/^>\s+/mu', '', $text);                // > 引用
        $text = preg_replace('/\n{3,}/', "\n\n", $text);             // 多余空行合并
        return trim($text);
    }
}
