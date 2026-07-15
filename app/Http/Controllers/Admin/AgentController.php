<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentExecution;
use App\Models\Workspace;
use App\Services\Agent\AgentDispatcherService;
use App\Services\Agent\AgentToolRegistry;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * v2.6.0 智能体管理控制器。
 *
 * 提供五智能体工作流的触发、监控、历史查询功能。
 */
class AgentController extends Controller
{
    public function __construct(
        private readonly AgentDispatcherService $dispatcher,
        private readonly AgentToolRegistry $registry,
    ) {}

    /**
     * 智能体总览页。
     */
    public function index(Request $request): View
    {
        /** @var \App\Models\Admin $admin */
        $admin = auth('admin')->user();
        $isSuperAdmin = $admin && $admin->isSuperAdmin();

        $wsQuery = Workspace::query()->where('status', 'active')->orderBy('name');
        if (! $isSuperAdmin) {
            $wsIds = $admin->scopedWorkspaceIds();
            if ($wsIds === null) { /* super admin */ }
            elseif ($wsIds === []) { $wsQuery->whereRaw('1=0'); }
            else { $wsQuery->whereIn('id', $wsIds); }
        }
        $workspaces = $wsQuery->get();

        // 获取最近的执行记录
        $recentExecutions = AgentExecution::query()
            ->with('workspace')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('admin.agents.index', [
            'pageTitle' => '智能体工作流',
            'activeMenu' => 'agents',
            'adminSiteName' => AdminWeb::siteName(),
            'workspaces' => $workspaces,
            'recentExecutions' => $recentExecutions,
            'toolCount' => $this->registry->count(),
            'stats' => $this->loadStats(),
        ]);
    }

    /**
     * 启动新的智能体工作流。
     */
    public function start(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'keywords' => ['nullable', 'string'],
            'brand_name' => ['nullable', 'string', 'max:100'],
            'task_id' => ['nullable', 'integer'],
            'platforms' => ['nullable', 'array'],
            'content_count' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $this->authorizeWorkspaceAccess((int) $payload['workspace_id']);

        $keywords = ! empty($payload['keywords'])
            ? array_map('trim', preg_split('/[\n,，、]+/u', $payload['keywords']))
            : [];

        $adminId = (int) (auth('admin')->id() ?? 0);

        $execution = $this->dispatcher->start(
            workspaceId: (int) $payload['workspace_id'],
            inputData: [
                'keywords' => $keywords,
                'brand_name' => $payload['brand_name'] ?? '',
                'task_id' => (int) ($payload['task_id'] ?? 0),
                'platforms' => $payload['platforms'] ?? ['toutiao', 'baijiahao'],
                'content_count' => (int) ($payload['content_count'] ?? 3),
            ],
            triggerType: 'manual',
            adminId: $adminId > 0 ? $adminId : null,
        );

        return redirect()
            ->route('admin.agents.show', $execution->id)
            ->with('success', "智能体工作流已启动 (#{$execution->id})");
    }

    /**
     * 查看执行详情。
     */
    public function show(int $executionId): View
    {
        $execution = AgentExecution::query()
            ->with('workspace')
            ->findOrFail($executionId);

        $this->authorizeWorkspaceAccess((int) $execution->workspace_id);

        return view('admin.agents.show', [
            'pageTitle' => "工作流 #{$executionId}",
            'activeMenu' => 'agents',
            'adminSiteName' => AdminWeb::siteName(),
            'execution' => $execution,
            'stateLabels' => $this->stateLabels(),
        ]);
    }

    /**
     * 手动重试失败的工作流。
     */
    public function retry(int $executionId): RedirectResponse
    {
        $execution = AgentExecution::query()->findOrFail($executionId);
        $this->authorizeWorkspaceAccess((int) $execution->workspace_id);

        if (! $execution->isFailed()) {
            return back()->withErrors('只能重试失败的工作流');
        }

        $this->dispatcher->resume($executionId);

        return redirect()
            ->route('admin.agents.show', $executionId)
            ->with('success', '工作流已重新启动');
    }

    // ── 辅助方法 ──────────────────────────────────────

    private function loadStats(): array
    {
        return [
            'total_executions' => AgentExecution::query()->count(),
            'completed' => AgentExecution::query()->where('current_state', 'completed')->count(),
            'failed' => AgentExecution::query()->where('current_state', 'failed')->count(),
            'in_progress' => AgentExecution::query()
                ->whereNotIn('current_state', ['completed', 'failed', 'idle'])
                ->count(),
            'tools_registered' => $this->registry->count(),
        ];
    }

    private function stateLabels(): array
    {
        return [
            'idle' => ['label' => '等待中', 'color' => 'gray'],
            'scouting' => ['label' => '侦察中', 'color' => 'blue'],
            'planning' => ['label' => '策略规划', 'color' => 'indigo'],
            'writing' => ['label' => '内容生产', 'color' => 'purple'],
            'deploying' => ['label' => '分发执行', 'color' => 'orange'],
            'reviewing' => ['label' => '复盘分析', 'color' => 'teal'],
            'completed' => ['label' => '已完成', 'color' => 'green'],
            'failed' => ['label' => '失败', 'color' => 'red'],
        ];
    }
}
