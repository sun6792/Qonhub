<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiVisibilityCheck;
use App\Models\AiVisibilitySnapshot;
use App\Models\Workspace;
use App\Services\GeoFlow\AiVisibilityService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiVisibilityController extends Controller
{
    public function __construct(
        private readonly AiVisibilityService $aiVisibilityService,
    ) {}

    public function index(): View
    {
        $workspaces = Workspace::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $workspaceSummaries = $workspaces->map(function (Workspace $ws): array {
            $latestSnapshots = AiVisibilitySnapshot::query()
                ->where('workspace_id', (int) $ws->id)
                ->where('snapshot_date', '>=', now()->subDays(7))
                ->orderByDesc('snapshot_date')
                ->get();

            return [
                'id' => (int) $ws->id,
                'name' => (string) $ws->name,
                'slug' => (string) $ws->slug,
                'has_data' => $latestSnapshots->isNotEmpty(),
                'last_check' => $latestSnapshots->max('snapshot_date')?->toDateString() ?? '-',
                'checks_today' => AiVisibilityCheck::query()
                    ->where('workspace_id', (int) $ws->id)
                    ->whereDate('checked_at', now())
                    ->count(),
                'mentioned_today' => AiVisibilityCheck::query()
                    ->where('workspace_id', (int) $ws->id)
                    ->whereDate('checked_at', now())
                    ->where('mentioned', true)
                    ->count(),
            ];
        });

        return view('admin.ai-visibility.index', [
            'pageTitle' => 'AI引用追踪',
            'activeMenu' => 'ai-visibility',
            'adminSiteName' => AdminWeb::siteName(),
            'workspaces' => $workspaces,
            'workspaceSummaries' => $workspaceSummaries,
            'globalStats' => [
                'total_workspaces' => $workspaces->count(),
                'active_workspaces' => $workspaces->where('status', 'active')->count(),
                'total_operators' => 0,
                'total_articles' => AiVisibilityCheck::query()->whereDate('checked_at', now())->count(),
            ],
        ]);
    }

    public function show(int $workspaceId): View|RedirectResponse
    {
        $workspace = Workspace::query()->whereKey($workspaceId)->first();
        if (! $workspace) {
            return redirect()->route('admin.ai-visibility.index')->withErrors('工作空间不存在');
        }

        $visibilityData = $this->aiVisibilityService->clientVisibilityData($workspaceId);

        $recentChecks = AiVisibilityCheck::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('checked_at')
            ->limit(30)
            ->get();

        return view('admin.ai-visibility.show', [
            'pageTitle' => 'AI引用 - '.$workspace->name,
            'activeMenu' => 'ai-visibility',
            'adminSiteName' => AdminWeb::siteName(),
            'workspace' => $workspace,
            'visibilityData' => $visibilityData,
            'recentChecks' => $recentChecks,
            'platforms' => \App\Services\GeoFlow\AiVisibilityService::AI_PLATFORMS,
        ]);
    }

    public function triggerCheck(Request $request): RedirectResponse
    {
        $workspaceId = (int) $request->input('workspace_id', 0);
        if ($workspaceId <= 0) {
            return back()->withErrors('请选择工作空间');
        }

        $workspace = Workspace::query()->whereKey($workspaceId)->first();
        if (! $workspace) {
            return back()->withErrors('工作空间不存在');
        }

        $result = $this->aiVisibilityService->checkWorkspace($workspace);

        return back()->with('message', "检测完成: {$result['total']}次查询, {$result['mentioned']}次品牌提及");
    }
}
