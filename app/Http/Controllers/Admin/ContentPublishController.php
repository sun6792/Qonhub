<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentPublishTask;
use App\Models\Workspace;
use App\Services\GeoFlow\EnterpriseAnchorService;
use App\Services\GeoFlow\Publishing\AccountPoolService;
use App\Services\GeoFlow\Publishing\ContentPublishService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 全渠道内容发布控制器（运营端）。
 *
 * 集成 ContentArmory 弹药库作为统一内容入口，
 * 复用 Workspace 租户隔离 + EnterpriseAnchor 锚点体系。
 */
class ContentPublishController extends Controller
{
    public function __construct(
        private readonly ContentPublishService $publishService,
        private readonly AccountPoolService $accountPool,
    ) {}

    // ── 一键发布中心（主页面） ──────────────────────────

    public function index(Request $request): View
    {
        $workspaceId = (int) $request->query('workspace_id', 0);
        if ($workspaceId > 0) {
            $this->authorizeWorkspaceAccess($workspaceId);
        }
        $workspace = $workspaceId > 0
            ? Workspace::query()->findOrFail($workspaceId)
            : null;

        // 发布任务历史
        $recentTasks = ContentPublishTask::query()
            ->when($workspace, fn ($q) => $q->where('workspace_id', (int) $workspace->id))
            ->with('results')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // 账号统计
        $accountStats = $workspace ? [
            'self_media' => $this->accountPool->listForWorkspace((int) $workspace->id, 'self_media')->count(),
            'news_media' => $this->accountPool->listForWorkspace((int) $workspace->id, 'news_media')->count(),
            'b2b' => $this->accountPool->listForWorkspace((int) $workspace->id, 'b2b')->count(),
        ] : null;

        // 锚点概括
        $platforms = EnterpriseAnchorService::allAnchorPlatforms();

        return view('admin.content-publish.index', [
            'pageTitle' => '全渠道分发运营台',
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'workspace' => $workspace,
            'recentTasks' => $recentTasks,
            'accountStats' => $accountStats,
            'platforms' => $platforms,
        ]);
    }

    // ── 创建发布任务 ────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'article_ids' => ['required', 'array', 'min:1'],
            'article_ids.*' => ['integer', 'exists:articles,id'],
            'platform_keys' => ['required', 'array', 'min:1'],
            'platform_keys.*' => ['string'],
            'use_smart_scheduling' => ['boolean'],
            'use_content_rewrite' => ['boolean'],
        ]);

        $this->authorizeWorkspaceAccess((int) $payload['workspace_id']);
        $workspace = Workspace::query()->findOrFail((int) $payload['workspace_id']);

        $task = $this->publishService->createPublishTask(
            workspace: $workspace,
            articleIds: $payload['article_ids'],
            platformKeys: $payload['platform_keys'],
            options: [
                'use_smart_scheduling' => $payload['use_smart_scheduling'] ?? true,
                'use_content_rewrite' => $payload['use_content_rewrite'] ?? true,
            ],
            createdByAdminId: (int) ($request->user('admin')?->id ?? 0),
        );

        // 入队执行
        $this->publishService->dispatchPublishTask($task);

        return redirect()
            ->route('admin.content-publish.task', ['taskId' => $task->id])
            ->with('success', "发布任务已创建，共 {$task->total_jobs} 个分发作业");
    }

    // ── 发布任务详情 ────────────────────────────────────

    public function taskDetail(int $taskId): View
    {
        $task = ContentPublishTask::query()
            ->with(['results.article', 'results.account', 'workspace'])
            ->findOrFail($taskId);

        $this->authorizeWorkspaceAccess((int) $task->workspace_id);

        return view('admin.content-publish.task-detail', [
            'pageTitle' => "发布任务 #{$task->id}",
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'task' => $task,
        ]);
    }

    // ── 重试失败作业 ────────────────────────────────────

    public function retry(int $taskId): RedirectResponse
    {
        $task = ContentPublishTask::query()->findOrFail($taskId);
        $this->authorizeWorkspaceAccess((int) $task->workspace_id);
        $this->publishService->retryFailed($task);

        return redirect()
            ->route('admin.content-publish.task', ['taskId' => $taskId])
            ->with('success', '失败作业已重新入队');
    }

    // ── 取消任务 ────────────────────────────────────────

    public function cancel(int $taskId): RedirectResponse
    {
        $task = ContentPublishTask::query()->findOrFail($taskId);
        $this->authorizeWorkspaceAccess((int) $task->workspace_id);
        $this->publishService->cancelTask($task);

        return redirect()
            ->route('admin.content-publish.index')
            ->with('success', '任务已取消');
    }

    // ── API: 任务进度（供前端轮询） ─────────────────────

    public function taskProgress(int $taskId): \Illuminate\Http\JsonResponse
    {
        $task = ContentPublishTask::query()->findOrFail($taskId);
        $this->authorizeWorkspaceAccess((int) $task->workspace_id);

        return response()->json([
            'ok' => true,
            'data' => [
                'status' => $task->status,
                'progress_percent' => $task->progress_percent,
                'completed_jobs' => $task->completed_jobs,
                'failed_jobs' => $task->failed_jobs,
                'total_jobs' => $task->total_jobs,
            ],
        ]);
    }
}
