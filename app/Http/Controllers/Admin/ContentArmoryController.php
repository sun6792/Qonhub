<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Admin;
use App\Models\ArmoryPublishLog;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\AiModel;
use App\Models\DistributionChannel;
use App\Models\Workspace;
use App\Services\GeoFlow\WorkspaceService;
use App\Services\GeoFlow\DistributionPayloadBuilder;
use App\Services\GeoFlow\DistributionPublisherManager;
use App\Services\GeoFlow\Publishing\RpaEngineClient;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\GeoPlatformRules;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use App\Ai\Agents\MarkdownContentWriterAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ContentArmoryController extends Controller
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly DistributionPayloadBuilder $payloadBuilder,
        private readonly DistributionPublisherManager $publisherManager,
    ) {}

    /**
     * 内容弹药库首页：文章列表 + 模板组 + 对应平台。
     */
    public function index(Request $request, WorkspaceService $workspaceService): View
    {
        $search = trim((string) $request->query('search', ''));
        $workspaceId = max(0, (int) $request->query('workspace_id', 0));
        $perPage = 20;

        $articlesQuery = Article::query()
            ->with('task:id,name')
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $articlesQuery->where(function ($q) use ($search): void {
                $q->where('title', 'ilike', '%'.$search.'%')
                    ->orWhere('keywords', 'ilike', '%'.$search.'%');
            });
        }

        // 按工作空间过滤：非超管默认只看自己绑定的 workspace
        // v2.8.1 修复：Agent 管道生成的文章 task_id 可能为 NULL，改用 workspace_assignments 过滤
        if ($workspaceId > 0) {
            $articleIds = $workspaceService->assignedIds($workspaceId, Article::class);
            if ($articleIds === []) {
                $articlesQuery->whereRaw('1=0');
            } else {
                $articlesQuery->whereIn('id', $articleIds);
            }
        } elseif (! Auth::guard('admin')->user()?->isSuperAdmin()) {
            $adminWsIds = Auth::guard('admin')->user()?->scopedWorkspaceIds() ?? [];
            if ($adminWsIds === []) {
                $articlesQuery->whereRaw('1=0');
            } elseif ($adminWsIds !== null) {
                $articlesQuery->whereIn('id', function ($sub) use ($adminWsIds) {
                    $sub->select('assignable_id')->from('workspace_assignments')
                        ->where('assignable_type', Article::class)
                        ->whereIn('workspace_id', $adminWsIds);
                });
            }
        }

        $articles = $articlesQuery->paginate($perPage)->withQueryString();

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        $isSuperAdmin = $admin instanceof Admin && $admin->isSuperAdmin();

        // 工作空间下拉列表
        $workspaces = $isSuperAdmin
            ? Workspace::query()->where('status', 'active')->orderBy('name')->get()
            : $workspaceService->listForOperator((int) $admin->id)->where('status', 'active');

        /** @var list<array{key:string, name:string, prompt:string, style:string, platforms:list<array{name:string, login_url:string}>}> */
        $templates = config('media-templates.templates', []);

        $templateStats = [];
        foreach ($templates as $tpl) {
            $templateStats[$tpl['key']] = count($tpl['platforms']);
        }

        // v2.9: 定时发布计划
        $scheduledItems = \App\Models\PublishingSchedule::query()
            ->when($workspaceId > 0, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->with(['article'])
            ->orderByDesc('scheduled_at')
            ->limit(20)
            ->get();

        return view('admin.distribution.armory', [
            'pageTitle' => '内容弹药库',
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'articles' => $articles,
            'templates' => $templates,
            'templateStats' => $templateStats,
            'search' => $search,
            'workspaceId' => $workspaceId,
            'workspaces' => $workspaces,
            'scheduledItems' => $scheduledItems,
        ]);
    }

    /**
     * AI 改写接口：一篇文章 → 一个模板 → 改写后内容。
     */
    public function rewrite(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'article_id' => ['required', 'integer', 'min:1'],
            'template_key' => ['required', 'string'],
        ]);

        $articleId = (int) $payload['article_id'];
        $templateKey = (string) $payload['template_key'];

        /** @var Article|null $article */
        $article = Article::query()->whereKey($articleId)->first();
        if (! $article) {
            return response()->json(['ok' => false, 'error' => '文章不存在'], 404);
        }
        $this->authorizeOperatorAccess($articleId, Article::class);

        // GEO 评分：改写前打分
        $scorer = app(\App\Services\GeoFlow\GeoContentScorer::class);

        /** @var list<array{key:string, name:string, prompt:string, style:string, geo_dimensions?:array, platform_type?:string}> $templates */
        $templates = config('media-templates.templates', []);
        $template = collect($templates)->firstWhere('key', $templateKey);
        if (! $template) {
            return response()->json(['ok' => false, 'error' => '模板不存在'], 404);
        }

        // 使用平台自适应 GEO 维度权重评分
        $geoDimensions = $template['geo_dimensions'] ?? null;
        $beforeScore = $scorer->score((string) $article->title, (string) $article->content, $geoDimensions);

        // 段落级诊断（对齐 geoskills geo-fix-content）
        $diagnosis = $scorer->diagnoseParagraphs(strip_tags((string) $article->content));
        $fixPrompt = $scorer->buildFixPrompt($diagnosis);

        try {
            $rewritten = $this->rewriteWithAi($article, $template, $fixPrompt);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'AI 改写失败: '.$e->getMessage(),
            ], 500);
        }

        // GEO 评分：改写后打分（使用相同平台权重）+ 对比
        $afterScore = $scorer->score($article->title, $rewritten, $geoDimensions);

        return response()->json([
            'ok' => true,
            'title' => $article->title,
            'geo_score' => [
                'before' => $beforeScore['score'],
                'after' => $afterScore['score'],
                'improvement' => $afterScore['score'] - $beforeScore['score'],
                'grade' => $beforeScore['grade'] . ' → ' . $afterScore['grade'],
                'suggestions' => $afterScore['suggestions'],
                'weights_used' => $afterScore['weights_used'] ?? [],
            ],
            'diagnosis' => [
                'total_paragraphs' => $diagnosis['summary']['total_paragraphs'] ?? 0,
                'issues_found' => $diagnosis['summary']['issues_found'] ?? 0,
                'hedge_paragraphs' => $diagnosis['summary']['hedge_paragraphs'] ?? 0,
                'weak_data_paragraphs' => $diagnosis['summary']['weak_data_paragraphs'] ?? 0,
                'fix_count' => count($diagnosis['fix_targets'] ?? []),
            ],
            'rewritten' => $rewritten,
            'template_name' => $template['name'],
            'platform_type' => $template['platform_type'] ?? 'self_media',
        ]);
    }

    /**
     * 弹药库改写内容一键推送到分发渠道。
     */
    public function publishToChannels(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'article_id' => ['required', 'integer', 'min:1'],
            'template_key' => ['required', 'string'],
            'rewritten_title' => ['required', 'string', 'max:500'],
            'rewritten_content' => ['required', 'string'],
            'channel_ids' => ['required', 'array', 'min:1'],
            'channel_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $articleId = (int) $payload['article_id'];
        $templateKey = (string) $payload['template_key'];
        $rewrittenTitle = (string) $payload['rewritten_title'];
        $rewrittenContent = (string) $payload['rewritten_content'];
        $channelIds = array_map('intval', $payload['channel_ids']);

        /** @var Article|null $article */
        $article = Article::query()->whereKey($articleId)->first();
        if (! $article) {
            return response()->json(['ok' => false, 'error' => '文章不存在'], 404);
        }
        $this->authorizeOperatorAccess($articleId, Article::class);

        $adminId = Auth::guard('admin')->id();
        $results = [];

        foreach ($channelIds as $channelId) {
            /** @var DistributionChannel|null $channel */
            $channel = DistributionChannel::query()->whereKey($channelId)->first();

            if (! $channel) {
                $results[] = ['channel_id' => $channelId, 'ok' => false, 'error' => '渠道不存在'];
                continue;
            }

            try {
                // 构建带有改写内容的载荷
                $basePayload = $this->payloadBuilder->build($article);
                $basePayload['article']['title'] = $rewrittenTitle;
                $basePayload['article']['content'] = $rewrittenContent;
                $basePayload['article']['content_html'] = $rewrittenContent;
                $basePayload['armory'] = [
                    'source' => 'content_armory',
                    'template_key' => $templateKey,
                    'original_article_id' => $articleId,
                ];

                // 创建分发记录
                $distribution = ArticleDistribution::query()->create([
                    'article_id' => $articleId,
                    'distribution_channel_id' => $channelId,
                    'action' => 'publish',
                    'status' => 'queued',
                    'next_retry_at' => now(),
                ]);

                // 发布日志
                ArmoryPublishLog::query()->create([
                    'article_id' => $articleId,
                    'template_key' => $templateKey,
                    'channel_id' => $channelId,
                    'rewritten_title' => $rewrittenTitle,
                    'rewritten_content' => mb_substr($rewrittenContent, 0, 500),
                    'status' => 'queued',
                    'message' => '已入队，等待分发',
                    'published_by_admin_id' => $adminId,
                ]);

                // 直接推送（同步尝试）
                try {
                    $publisher = $this->publisherManager->forChannel($channel);
                    $publishResult = $publisher->publish($distribution, $basePayload);

                    $distribution->forceFill([
                        'status' => 'synced',
                        'synced_at' => now(),
                    ])->save();

                    ArmoryPublishLog::query()->where('article_id', $articleId)
                        ->where('channel_id', $channelId)
                        ->where('template_key', $templateKey)
                        ->latest()->first()?->forceFill([
                            'status' => 'success',
                            'message' => '推送成功',
                            'response_meta' => $publishResult,
                        ])->save();

                    $results[] = [
                        'channel_id' => $channelId,
                        'channel_name' => $channel->name,
                        'ok' => true,
                        'message' => '推送成功',
                    ];
                } catch (Throwable $e) {
                    // 同步推送失败，入队异步重试
                    ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                        ->onQueue('distribution')
                        ->afterCommit();

                    $results[] = [
                        'channel_id' => $channelId,
                        'channel_name' => $channel->name,
                        'ok' => true,
                        'message' => '已入队，后台异步推送中',
                    ];
                }
            } catch (Throwable $e) {
                $results[] = [
                    'channel_id' => $channelId,
                    'channel_name' => $channel->name ?? '未知',
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $successCount = count(array_filter($results, static fn (array $r): bool => $r['ok']));

        return response()->json([
            'ok' => $successCount > 0,
            'results' => $results,
            'summary' => "{$successCount}/".count($results).' 个渠道推送成功',
        ]);
    }

    /**
     * [新增] 弹药库内容推送到 RPA 引擎 → 媒体平台（头条/百家号/小红书）。
     * POST /geo_admin/distribution/armory/publish-to-rpa
     */
    public function publishToRpa(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'article_id' => ['required', 'integer', 'min:1'],
            'workspace_id' => ['required', 'integer', 'min:1'],
            'platform' => ['required', 'string', 'in:toutiao_publish,baijiahao_publish,xiaohongshu_publish,sohu_publish'],
            'rewritten_title' => ['nullable', 'string', 'max:500'],
            'rewritten_content' => ['nullable', 'string'],
        ]);

        $articleId = (int) $payload['article_id'];
        $workspaceId = (int) $payload['workspace_id'];
        $platform = $payload['platform'];

        $article = Article::query()->whereKey($articleId)->first();
        if (! $article) {
            return response()->json(['ok' => false, 'error' => '文章不存在'], 404);
        }
        $this->authorizeOperatorAccess($articleId, Article::class);
        $this->authorizeWorkspaceAccess($workspaceId);

        // 一键分发：未传标题/内容时自动取原文
        $title = $payload['rewritten_title'] ?: (string) $article->title;
        $content = $payload['rewritten_content'] ?: (string) $article->content;

        $platformNames = [
            'toutiao_publish' => '头条号',
            'baijiahao_publish' => '百家号',
            'xiaohongshu_publish' => '小红书',
            'sohu_publish' => '搜狐号',
        ];

        try {
            $rpaClient = app(RpaEngineClient::class);

            // 健康检查
            $health = $rpaClient->healthCheck();
            if (! ($health['healthy'] ?? false)) {
                return response()->json(['ok' => false, 'error' => 'RPA 引擎未启动。请在 rpa-engine 目录执行 node server.js'], 503);
            }

            // v2.9: 使用异步模式，避免 PHP 30秒超时
            $taskId = $rpaClient->createTaskAsync([
                'platform' => $platform,
                'platform_name' => $platformNames[$platform],
                'action' => 'publish_article',
                'account' => [],
                'enterprise' => ['workspace_id' => $workspaceId],
                'content' => [
                    'title' => $title,
                    'content' => $content,
                    'article_id' => $articleId,
                ],
                'options' => [
                    'workspace_id' => $workspaceId,
                    'timeout_seconds' => 300,
                    'cover_image' => base_path('豆流2033.png'),
                ],
            ]);

            Log::info('RPA publish task submitted', ['task_id' => $taskId, 'platform' => $platform, 'article_id' => $articleId]);

            return response()->json([
                'ok' => true,
                'task_id' => $taskId,
                'message' => "发布任务已提交（{$taskId}），正在后台自动发布到{$platformNames[$platform]}...",
            ]);
        } catch (Throwable $e) {
            Log::error('RPA publish error', ['message' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'error' => 'RPA引擎不可用，请确认本地助手已启动（cd rpa-engine && node server.js）',
            ], 503);
        }
    }

    /**
     * 获取可用的分发渠道列表（用于弹药库发布面板）。
     */
    public function availableChannels(): JsonResponse
    {
        $channels = DistributionChannel::query()
            ->where('status', 'active')
            ->select(['id', 'name', 'channel_type', 'domain'])
            ->orderBy('name')
            ->get()
            ->map(static fn (DistributionChannel $c): array => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'type' => (string) $c->channelType(),
                'domain' => (string) $c->domain,
            ]);

        return response()->json(['ok' => true, 'channels' => $channels]);
    }

    /**
     * 定时发布 — 将文章加入 publishing_schedules 队列。
     */
    public function schedulePublish(Request $request): \Illuminate\Http\JsonResponse
    {
        $payload = $request->validate([
            'article_ids' => ['required', 'array', 'min:1'],
            'article_ids.*' => ['integer', 'exists:articles,id'],
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'platform' => ['required', 'string', 'in:toutiao_publish,baijiahao_publish,xiaohongshu_publish,sohu_publish'],
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        $count = 0;
        $skipped = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($payload, &$count, &$skipped) {
            foreach ($payload['article_ids'] as $articleId) {
                // 去重：同一篇文章+同一个平台已有 pending/processing 任务则跳过
                $exists = \App\Models\PublishingSchedule::query()
                    ->where('article_id', (int) $articleId)
                    ->where('platform', $payload['platform'])
                    ->whereIn('status', ['pending', 'processing'])
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                \App\Models\PublishingSchedule::create([
                    'workspace_id' => (int) $payload['workspace_id'],
                    'article_id' => (int) $articleId,
                    'platform' => $payload['platform'],
                    'scheduled_at' => $payload['scheduled_at'],
                    'status' => 'pending',
                ]);
                $count++;
            }
        });

        return response()->json([
            'ok' => true,
            'message' => "{$count} 篇文章已加入定时发布队列" . ($skipped > 0 ? "（{$skipped} 篇已存在，跳过）" : '') . "，将于 {$payload['scheduled_at']} 自动发布到 {$payload['platform']}",
        ]);
    }

    /**
     * 取消定时发布。
     */
    public function cancelSchedule(int $id): \Illuminate\Http\JsonResponse
    {
        $schedule = \App\Models\PublishingSchedule::findOrFail($id);
        if ($schedule->status === 'pending') {
            $schedule->status = 'cancelled';
            $schedule->save();
        }
        return response()->json(['ok' => true]);
    }

    /**
     * @param  array{key:string, name:string, prompt:string, style:string}  $template
     */
    private function rewriteWithAi(Article $article, array $template, string $diagnosisFixPrompt = ''): string
    {
        // 取第一个可用的 chat 模型
        /** @var AiModel|null $aiModel */
        $aiModel = AiModel::query()
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->first();

        if (! $aiModel) {
            throw new RuntimeException('没有可用的 AI 模型，请先在 AI 配置中添加并激活一个 Chat 模型');
        }

        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new RuntimeException('AI 模型 API 地址为空');
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('AI 模型密钥为空');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('armory', $driver, $providerUrl, $apiKey);

        $agent = new MarkdownContentWriterAgent(
            instructions: $template['style'],
            maxTokens: (int) ($aiModel->max_tokens ?: 4096),
        );

        // 保留原文主体，去掉 HTML 标签
        $originalContent = strip_tags((string) $article->content);
        $maxInputChars = 8000;
        if (mb_strlen($originalContent, 'UTF-8') > $maxInputChars) {
            $originalContent = mb_substr($originalContent, 0, $maxInputChars, 'UTF-8');
        }

        // 构建公司/品牌推广上下文
        $platformType = $template['platform_type'] ?? 'self_media';
        $companyProfile = $this->buildCompanyProfile($article, $platformType);

        // 知识库关键数据提取（注入改写 Prompt）
        $kbContext = '';
        if ($article->task_id) {
            $kbs = \App\Models\KnowledgeBase::query()
                ->whereIn('id', function ($q) use ($article) {
                    $q->select('knowledge_base_id')->from('task_knowledge_bases')->where('task_id', (int) $article->task_id);
                })
                ->get();
            if ($kbs->isNotEmpty()) {
                $extractor = app(\App\Services\GeoFlow\KnowledgeKeyExtractor::class);
                $extracted = $extractor->extract($kbs->first());
                $kbContext = "\n=== 知识库关键数据（必须融入文章） ===\n"
                    .$extractor->toPromptContext($extracted, 1500)."\n";
            }
        }

        // 拼接完整 prompt：公司信息 + 知识库数据 + 原文 + GEO优化指令
        $systemPrompt = $companyProfile."\n\n"
            .$kbContext
            ."=== 原始文章（请保留所有关键信息） ===\n"
            ."标题：{$article->title}\n"
            ."关键词：{$article->keywords}\n"
            ."摘要：".mb_substr(strip_tags((string) $article->excerpt), 0, 300, 'UTF-8')."\n\n"
            .$originalContent."\n\n"
            ."=== 改写要求 ===\n"
            .$template['prompt']."\n\n"
            .$diagnosisFixPrompt
            ."=== GEO 优化要求（提高 AI 搜索引擎引用率） ===\n"
            ."1. 优先使用上文「知识库关键数据」中的统计数据、百分比、具体数值\n"
            ."2. 使用 Q&A 结构（问答形式）提高 AI 引用概率\n"
            ."3. 删除所有虚词（可能、大概、似乎等），改用确定性表述\n"
            ."4. 如果有专家引言或权威数据来源，保留并加粗\n"
            ."5. 使用 H2/H3 小标题 + 列表，让 AI 搜索引擎更容易理解\n\n"
            .GeoPlatformRules::forTemplate($template['key'])."\n\n"
            ."请直接输出改写后的完整文章（含标题）：";


        try {
            $response = $agent->prompt($systemPrompt, [], $providerName, (string) ($aiModel->model_id ?? ''));
        } catch (Throwable $e) {
            throw new RuntimeException(OpenAiRuntimeProvider::normalizeApiException($e, $providerUrl));
        }

        $content = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? ''));
        if ($content === '') {
            throw new RuntimeException('AI 返回空内容，请重试');
        }

        // 更新模型用量
        AiModel::query()->whereKey((int) $aiModel->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);

        // 剥离 Markdown 标记：输出干净纯文本，兼容头条/百家号等不支持 Markdown 的平台
        $content = preg_replace('/\*\*(.+?)\*\*/u', '$1', $content);
        $content = preg_replace('/\*(.+?)\*/u', '$1', $content);
        $content = preg_replace('/^#{1,6}\s+/mu', '', $content);
        $content = preg_replace('/^[-*+]\s+/mu', '  ', $content);
        $content = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return trim($content);
    }

    /**
     * 构建公司/品牌推广上下文，供 AI 改写时自然植入。
     */
    /**
     * Build company profile context with platform-type-aware brand exposure rules.
     *
     * Platform types (aligned with geoskills Entity & Brand Signals dimension):
     *   - self_media: strict (brand 0-1x) — platforms have aggressive AI marketing detection
     *   - b2b:        moderate (brand 2-3x) — brand consistency IS a GEO signal
     *   - tech_blog:  balanced (brand 1-2x) — brand as solution case study
     *   - geo_site:   balanced (brand 2-3x) — needs entity signals for AI recognition
     */
    private function buildCompanyProfile(Article $article, string $platformType = 'self_media'): string
    {
        $siteName = config('geoflow.site_name', 'Qonhub AI');
        $siteFullName = config('geoflow.site_full_name', 'Qonhub AI内容生成系统');

        // 从任务关联的知识库提取公司介绍
        $companyIntro = '';
        $task = $article->task;
        if ($task) {
            $knowledgeBaseIds = [];
            if ((int) ($task->knowledge_base_id ?? 0) > 0) {
                $knowledgeBaseIds[] = (int) $task->knowledge_base_id;
            }
            $latestKb = DB::table('knowledge_bases')
                ->whereIn('id', $knowledgeBaseIds)
                ->orderByDesc('id')
                ->first();
            if ($latestKb && ! empty($latestKb->content)) {
                $companyIntro = mb_substr(strip_tags((string) $latestKb->content), 0, 800, 'UTF-8');
            }
        }

        $siteUrl = rtrim((string) config('app.url', 'http://localhost:18080'), '/');
        $contactInfo = config('geoflow.contact_info', '');

        // 根据平台类型选择品牌露出规则
        $brandRules = $this->getBrandRulesForPlatformType($platformType);

        $profile = "=== 行业背景信息（{$brandRules['label']}） ===\n"
            ."行业参考：{$siteFullName}\n";

        if ($companyIntro !== '') {
            $profile .= "领域知识：{$companyIntro}\n";
        }

        $profile .= $brandRules['instructions'];

        return $profile;
    }

    /**
     * @return array{label:string, instructions:string}
     */
    private function getBrandRulesForPlatformType(string $platformType): array
    {
        return match ($platformType) {
            'self_media' => [
                'label' => '自媒体平台——品牌最小化',
                'instructions' =>
                    "\n【品牌植入规则——自媒体平台（头条/百家号/知乎/小红书/B站/短视频）】\n"
                    ."这些平台有AI营销检测（朱雀大模型等），品牌露出需极克制：\n"
                    ."- 品牌最多出现 0-1 次，且必须是文章的\"案例之一\"，和其他品牌并列提及\n"
                    ."- 严禁文末加导流语句（\"如需了解更多\"\"访问官网\"\"联系我们\"等全部禁止）\n"
                    ."- 严禁写\"XX公司成立于XX年，致力于...\"这种企业介绍式段落\n"
                    ."- 正确方式：\"市面上的方案有几类，比如A公司（XX）做的XX方向...\"\n"
                    ."- 文章应该是行业视角的客观分析，品牌只是众多案例中的一个名字\n",
            ],
            'b2b' => [
                'label' => 'B2B平台——品牌一致性=GEO信号',
                'instructions' =>
                    "\n【品牌植入规则——B2B平台（爱采购/黄页88/1688/行业垂直站）】\n"
                    ."B2B平台上品牌一致性是GEO的Entity & Brand Signals维度（权重25%），\n"
                    ."适度露出品牌有助于AI引擎识别企业实体：\n"
                    ."- 品牌可出现 2-3 次，以\"技术提供方\"\"行业代表企业\"\"案例参考\"等角色出现\n"
                    ."- 文末可附企业信息卡片：\"【关于XX】XX公司专注XX领域X年 | 已服务XX+企业客户\"\n"
                    ."- 品牌露出要伴随数据支撑：\"XX公司服务了200+客户，平均帮客户提升效率34%\"\n"
                    ."- 严禁浮夸用语（\"最好\"\"第一\"\"国家级\"），用具体数据说话\n"
                    ."- 品牌一致性原则：名称、描述、联系方式在整篇文章中保持一致，供AI识别\n",
            ],
            'tech_blog' => [
                'label' => '技术博客——案例驱动品牌露出',
                'instructions' =>
                    "\n【品牌植入规则——技术博客（CSDN/博客园）】\n"
                    ."技术博客读者对硬广敏感，但可以以\"解决方案提供方\"身份适度提及品牌：\n"
                    ."- 品牌可出现 1-2 次，作为\"踩坑经验的总结者\"\"问题解决方案的来源\"\n"
                    ."- 正确方式：\"我们团队最终采用了XX（品牌名）方案，主要看中其...\"\n"
                    ."- 文末可附作者信息：\"作者：XX团队技术负责人，专注XX领域。博客：XX\"\n"
                    ."- 严禁直接推销或CTA，保持技术分享的调性\n",
            ],
            'geo_site' => [
                'label' => 'GEO站点——品牌实体信号增强',
                'instructions' =>
                    "\n【品牌植入规则——GEO目标站（Agent部署站点）】\n"
                    ."GEO站点的核心目标是让AI搜索引擎识别并引用品牌实体，\n"
                    ."需要平衡品牌信号强度与内容可信度：\n"
                    ."- 品牌可出现 2-3 次，以\"第三方客观视角\"提及\n"
                    ."- 文末附企业信息卡片：\"【关于XX】XX公司专注XX领域X年 | 官网:XX | 咨询:XX\"\n"
                    ."- 品牌名一致性（同一篇文章中不要混用简称/全称/英文名）→ 增强Entity Recognition\n"
                    ."- 引用至少1处行业数据标注品牌作为数据来源\n"
                    ."- 专家引言中可包含品牌技术负责人的真实身份\n",
            ],
            default => [
                'label' => '通用——品牌最小化',
                'instructions' =>
                    "\n【品牌植入规则——通用】\n"
                    ."- 品牌最多出现1次，以行业案例形式提及\n"
                    ."- 严禁硬广和CTA\n",
            ],
        };
    }
}
