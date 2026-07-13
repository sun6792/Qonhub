<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ContentPublishTask;
use App\Services\GeoFlow\EnterpriseAnchorService;
use App\Services\GeoFlow\GeoContentScorer;
use App\Services\GeoFlow\Publishing\ContentPublishService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 客户端一键发布中心。
 *
 * 客户可在看板中选择文章+平台，一键提交发布。
 * 数据严格 workspace 隔离，不可见任何凭证信息。
 * v2.4.0: GEO评分集成 + 分页筛选 + 渠道级联选择。
 */
class ContentPublishController extends Controller
{
    public function __construct(
        private readonly ContentPublishService $publishService,
        private readonly GeoContentScorer $geoScorer,
    ) {}

    // ── B2B 企业认证（v3.0 新增） ────────────────────────

    public function certify(Request $request): View|RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $workspace = $client->workspace;
        $anchorPlatforms = EnterpriseAnchorService::anchorPlatforms();

        // 按优先级排序（高权重在前）
        $sortedPlatforms = collect($anchorPlatforms)
            ->sortByDesc(fn($p) => ($p['citation_weight'] ?? 'low') === 'highest' ? 5 : (($p['citation_weight'] ?? 'low') === 'high' ? 4 : (($p['citation_weight'] ?? 'low') === 'medium' ? 3 : 1)))
            ->all();

        return view('client.content-publish.certify', [
            'workspace' => $workspace,
            'platforms' => $sortedPlatforms,
        ]);
    }

    public function certifyStore(Request $request): RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $workspace = $client->workspace;

        $payload = $request->validate([
            'platform_keys' => ['required', 'array', 'min:1'],
            'platform_keys.*' => ['string'],
        ]);

        $task = $this->publishService->createCertifyTask(
            workspace: $workspace,
            platformKeys: $payload['platform_keys'],
            options: ['task_name' => 'B2B认证-'.now()->format('m-d H:i')],
            requestedByClientId: (int) $client->id,
        );

        $this->publishService->dispatchPublishTask($task);

        return redirect()
            ->route('client.content-publish.index')
            ->with('success', "B2B认证任务已提交！共 {$task->total_platforms} 个平台，运营团队将尽快完成认证");
    }

    // ── 我的发布列表 ────────────────────────────────────

    public function index(Request $request): View|RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $workspace = $client->workspace;
        if (! $workspace || ! $workspace->isActive()) {
            Auth::guard('client')->logout();

            return redirect()->route('client.login')->withErrors('账号已停用');
        }

        $query = ContentPublishTask::query()
            ->where('workspace_id', (int) $workspace->id)
            ->with('results');

        // 按状态筛选
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // 按任务名称搜索
        if ($name = $request->query('task_name')) {
            $query->where('task_name', 'like', '%' . $name . '%');
        }

        // 按日期范围筛选
        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // 按类型筛选
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        $tasks = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        $statusOptions = [
            '' => '全部状态',
            'pending' => '待处理',
            'queued' => '排队中',
            'running' => '进行中',
            'completed' => '已完成',
            'partial_failed' => '部分失败',
            'failed' => '失败',
            'cancelled' => '已取消',
        ];

        $filters = $request->only(['status', 'task_name', 'date_from', 'date_to', 'type']);

        return view('client.content-publish.index', [
            'workspace' => $workspace,
            'tasks' => $tasks,
            'statusOptions' => $statusOptions,
            'filters' => $filters,
        ]);
    }

    // ── 新建发布 ────────────────────────────────────────

    public function create(Request $request): View|RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $workspace = $client->workspace;

        // 获取客户 workspace 下的已发布文章
        $articles = Article::query()
            ->where('status', 'published')
            ->whereIn('id', function ($query) use ($workspace) {
                $query->select('assignable_id')
                    ->from('workspace_assignments')
                    ->where('workspace_id', (int) $workspace->id)
                    ->where('assignable_type', Article::class);
            })
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();

        // 构建平台树（自媒体 + B2B + 渠道）
        $platformTree = app(\App\Services\GeoFlow\ChannelPlatformTree::class)
            ->build((int) $workspace->id);

        return view('client.content-publish.create', [
            'workspace' => $workspace,
            'articles' => $articles,
            'platformTree' => $platformTree,
        ]);
    }

    // ── 提交发布 ────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $workspace = $client->workspace;

        $payload = $request->validate([
            'article_ids' => ['required', 'array', 'min:1'],
            'article_ids.*' => ['integer'],
            'platform_keys' => ['required', 'array', 'min:1'],
            'platform_keys.*' => ['string'],
            'publish_mode' => ['nullable', 'integer'],
        ]);

        // ── GEO 评分前置校验 ──────────────────────────
        $articles = Article::query()
            ->whereIn('id', $payload['article_ids'])
            ->get();

        $geoResults = [];
        $warnings = [];
        $scorer = $this->geoScorer;

        foreach ($articles as $article) {
            $title = (string) ($article->title ?? '');
            $content = (string) ($article->content ?? '');

            $scoreResult = $scorer->score($title, $content);
            $finalScore = $scoreResult['score'];
            $wasEnhanced = false;

            // 低于70分自动增强
            if ($finalScore < 70) {
                $enhancedContent = $scorer->geoEnhance($title, $content);
                $enhancedScore = $scorer->quickScore($title, $enhancedContent);

                if ($enhancedScore >= 70) {
                    // 增强达标：更新文章内容
                    $article->forceFill(['content' => $enhancedContent])->save();
                    $finalScore = $enhancedScore;
                    $wasEnhanced = true;
                } else {
                    // 增强后仍不达标
                    $warnings[] = "《{$title}》GEO评分 {$enhancedScore} 分（{$scorer->grade($enhancedScore)}级），增强后仍未达到70分阈值，建议人工优化后再发布";
                }
            }

            $geoResults[(int) $article->id] = [
                'score' => $finalScore,
                'grade' => $scorer->grade($finalScore),
                'enhanced' => $wasEnhanced,
                'dimensions' => $scoreResult['dimensions'],
            ];
        }

        $avgGeoScore = (int) round(collect($geoResults)->avg('score'));

        $task = $this->publishService->createPublishTask(
            workspace: $workspace,
            articleIds: $payload['article_ids'],
            platformKeys: $payload['platform_keys'],
            options: [
                'use_smart_scheduling' => true,
                'use_content_rewrite' => true,
                'avg_geo_score' => $avgGeoScore,
                'geo_score_details' => $geoResults,
            ],
            requestedByClientId: (int) $client->id,
        );

        // 写入 GEO 评分到任务记录
        $task->forceFill([
            'avg_geo_score' => $avgGeoScore,
            'geo_score_details' => $geoResults,
        ])->save();

        $this->publishService->dispatchPublishTask($task);

        $message = "发布任务已提交！共 {$task->total_jobs} 个分发作业，平均GEO评分 {$avgGeoScore} 分（{$scorer->grade($avgGeoScore)}级）";
        if ($warnings) {
            $message .= '。' . count($warnings) . ' 篇文章评分偏低';
        }

        return redirect()
            ->route('client.content-publish.index')
            ->with('success', $message)
            ->with('warnings', $warnings);
    }

    // ── 发布详情 ────────────────────────────────────────

    public function show(int $taskId): View|RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $task = ContentPublishTask::query()
            ->where('workspace_id', (int) $client->workspace_id)
            ->with(['results.article'])
            ->findOrFail($taskId);

        // 按文章聚合发布状态
        $articleResults = [];
        foreach ($task->results as $r) {
            $aid = $r->article_id;
            if (! isset($articleResults[$aid])) {
                $articleResults[$aid] = [
                    'article' => $r->article,
                    'platforms' => [],
                    'success_count' => 0,
                    'failed_count' => 0,
                    'pending_count' => 0,
                ];
            }
            $articleResults[$aid]['platforms'][] = [
                'platform_key' => $r->platform_key,
                'status' => $r->status,
                'error_message' => $r->error_message,
                'remote_article_url' => $r->remote_article_url,
            ];
            if ($r->status === 'success') {
                $articleResults[$aid]['success_count']++;
            } elseif ($r->status === 'failed') {
                $articleResults[$aid]['failed_count']++;
            } else {
                $articleResults[$aid]['pending_count']++;
            }
        }

        // GEO 等级
        $grade = null;
        $gradeLabel = null;
        if ($task->avg_geo_score !== null) {
            $grade = $this->geoScorer->grade((int) $task->avg_geo_score);
            $gradeColors = [
                'A' => 'emerald', 'B' => 'green', 'C' => 'yellow',
                'D' => 'orange', 'F' => 'red',
            ];
            $gradeLabel = $gradeColors[$grade] ?? 'gray';
        }

        return view('client.content-publish.show', [
            'workspace' => $client->workspace,
            'task' => $task,
            'articleResults' => $articleResults,
            'grade' => $grade,
            'gradeLabel' => $gradeLabel,
        ]);
    }
}
