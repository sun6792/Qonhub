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
        if ($workspaceId > 0) {
            $taskIds = $workspaceService->assignedIds($workspaceId, \App\Models\Task::class);
            $articlesQuery->whereIn('task_id', $taskIds ?: [0]);
        } elseif (! Auth::guard('admin')->user()?->isSuperAdmin()) {
            $adminWsIds = Auth::guard('admin')->user()?->scopedWorkspaceIds() ?? [];
            if ($adminWsIds === []) {
                $articlesQuery->whereRaw('1=0'); // 无绑定 → 看不到任何文章
            } elseif ($adminWsIds !== null) {
                $articlesQuery->whereIn('task_id', function ($sub) use ($adminWsIds) {
                    $sub->select('id')->from('tasks')
                        ->whereIn('id', function ($s2) use ($adminWsIds) {
                            $s2->select('assignable_id')->from('workspace_assignments')
                                ->where('assignable_type', \App\Models\Task::class)
                                ->whereIn('workspace_id', $adminWsIds);
                        });
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
        $beforeScore = $scorer->score((string) $article->title, (string) $article->content);

        /** @var list<array{key:string, name:string, prompt:string, style:string}> $templates */
        $templates = config('media-templates.templates', []);
        $template = collect($templates)->firstWhere('key', $templateKey);
        if (! $template) {
            return response()->json(['ok' => false, 'error' => '模板不存在'], 404);
        }

        try {
            $rewritten = $this->rewriteWithAi($article, $template);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'AI 改写失败: '.$e->getMessage(),
            ], 500);
        }

        // GEO 评分：改写后打分 + 对比
        $afterScore = $scorer->score($article->title, $rewritten);

        return response()->json([
            'ok' => true,
            'title' => $article->title,
            'geo_score' => [
                'before' => $beforeScore['score'],
                'after' => $afterScore['score'],
                'improvement' => $afterScore['score'] - $beforeScore['score'],
                'grade' => $beforeScore['grade'] . ' → ' . $afterScore['grade'],
                'suggestions' => $afterScore['suggestions'],
            ],
            'rewritten' => $rewritten,
            'template_name' => $template['name'],
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
            'platform' => ['required', 'string', 'in:toutiao_publish,baijiahao_publish,xiaohongshu_publish'],
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
        ];

        try {
            $rpaClient = app(RpaEngineClient::class);
            $result = $rpaClient->executeTask([
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
                ],
            ]);

            if ($result['success'] ?? false) {
                Log::info('RPA publish success', ['platform' => $platform, 'article_id' => $articleId]);
                return response()->json([
                    'ok' => true,
                    'task_id' => $result['task_id'] ?? '',
                    'shop_url' => $result['shop_url'] ?? '',
                    'message' => "已发布到{$platformNames[$platform]}",
                ]);
            }

            return response()->json([
                'ok' => false,
                'error' => $result['error'] ?? 'RPA执行失败',
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
     * @param  array{key:string, name:string, prompt:string, style:string}  $template
     */
    private function rewriteWithAi(Article $article, array $template): string
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
        $companyProfile = $this->buildCompanyProfile($article);

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
    private function buildCompanyProfile(Article $article): string
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

        // 站点联系方式
        $siteUrl = rtrim((string) config('app.url', 'http://localhost:18080'), '/');
        $contactInfo = config('geoflow.contact_info', '');

        // v2.6.0: 品牌信息仅作为"行业背景数据"注入，不写推广指令
        $profile = "=== 行业背景信息（仅用于增加文章专业度，不要推销） ===\n"
            ."行业参考：{$siteFullName}\n";

        if ($companyIntro !== '') {
            $profile .= "领域知识：{$companyIntro}\n";
        }

        $profile .= "\n【重要：品牌植入规则——不遵守会触发平台营销检测导致限流】\n"
            ."- 品牌最多出现1次，且必须是文章的\"案例之一\"，和其他品牌并列提及\n"
            ."- 严禁文末加导流语句（\"如需了解更多\"\"访问官网\"\"联系我们\"等全部禁止）\n"
            ."- 严禁写\"XX公司成立于XX年，致力于...\"这种企业介绍式段落\n"
            ."- 正确的品牌出现方式：\"市面上的方案有几类，比如A公司（XX）做的XX方向...\"\n"
            ."- 文章应该是行业视角的客观分析，品牌只是众多案例中的一个名字\n";

        return $profile;
    }
}
