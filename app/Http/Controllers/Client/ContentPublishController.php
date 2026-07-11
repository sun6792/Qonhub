<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ContentPublishTask;
use App\Services\GeoFlow\EnterpriseAnchorService;
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
 */
class ContentPublishController extends Controller
{
    public function __construct(
        private readonly ContentPublishService $publishService,
    ) {}

    // ── B2B 企业认证（v3.0 新增） ────────────────────────

    public function certify(Request $request): View|RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $workspace = $client->workspace;

        return view('client.content-publish.certify', [
            'workspace' => $workspace,
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

        $tasks = ContentPublishTask::query()
            ->where('workspace_id', (int) $workspace->id)
            ->with('results')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return view('client.content-publish.index', [
            'workspace' => $workspace,
            'tasks' => $tasks,
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
        $articles = \App\Models\Article::query()
            ->where('status', 'published')
            ->whereIn('id', function ($query) use ($workspace) {
                $query->select('assignable_id')
                    ->from('workspace_assignments')
                    ->where('workspace_id', (int) $workspace->id)
                    ->where('assignable_type', \App\Models\Article::class);
            })
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();

        // 可用平台
        $platforms = EnterpriseAnchorService::allAnchorPlatforms();

        return view('client.content-publish.create', [
            'workspace' => $workspace,
            'articles' => $articles,
            'platforms' => $platforms,
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
        ]);

        $task = $this->publishService->createPublishTask(
            workspace: $workspace,
            articleIds: $payload['article_ids'],
            platformKeys: $payload['platform_keys'],
            options: [
                'use_smart_scheduling' => true,
                'use_content_rewrite' => true,
            ],
            requestedByClientId: (int) $client->id,
        );

        $this->publishService->dispatchPublishTask($task);

        return redirect()
            ->route('client.content-publish.index')
            ->with('success', "发布任务已提交！共 {$task->total_jobs} 个分发作业，请稍后查看结果");
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

        return view('client.content-publish.show', [
            'workspace' => $client->workspace,
            'task' => $task,
        ]);
    }
}
