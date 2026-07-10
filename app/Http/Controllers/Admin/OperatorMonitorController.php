<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Workspace;
use App\Services\GeoFlow\WorkspaceService;
use App\Support\AdminWeb;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperatorMonitorController extends Controller
{
    public function __construct(
        private readonly WorkspaceService $workspaceService,
    ) {}

    public function index(): View
    {
        // 按运营人员聚合
        $operators = Admin::query()
            ->where('status', 'active')
            ->orderBy('display_name')
            ->get()
            ->map(function (Admin $admin): array {
                $workspaces = Workspace::query()
                    ->where('owner_admin_id', (int) $admin->id)
                    ->orWhereHas('operators', fn ($q) => $q->where('admin_id', (int) $admin->id))
                    ->withCount([
                        'assignments as task_count' => fn ($q) => $q->where('assignable_type', \App\Models\Task::class),
                        'assignments as article_count' => fn ($q) => $q->where('assignable_type', \App\Models\Article::class),
                    ])
                    ->get();

                $totalArticles = $workspaces->sum('article_count');
                $activeTasks = $workspaces->sum('task_count');

                return [
                    'id' => (int) $admin->id,
                    'name' => (string) $admin->name,
                    'email' => (string) ($admin->email ?? ''),
                    'is_super' => (bool) ($admin->isSuperAdmin() ?? false),
                    'workspace_count' => $workspaces->count(),
                    'workspaces' => $workspaces->map(fn (Workspace $ws): array => [
                        'id' => (int) $ws->id,
                        'name' => (string) $ws->name,
                        'slug' => (string) $ws->slug,
                        'status' => (string) $ws->status,
                        'task_count' => (int) $ws->task_count,
                        'article_count' => (int) $ws->article_count,
                        'last_activity' => $ws->last_activity_at?->diffForHumans() ?? '暂无',
                    ])->all(),
                    'total_articles' => $totalArticles,
                    'active_tasks' => $activeTasks,
                ];
            });

        // 全局统计
        $globalStats = [
            'total_workspaces' => Workspace::query()->count(),
            'active_workspaces' => Workspace::query()->where('status', 'active')->count(),
            'total_operators' => $operators->count(),
            'total_articles' => \App\Models\Article::query()->where('status', 'published')->count(),
        ];

        return view('admin.operator-monitor.index', [
            'pageTitle' => '运营监控台',
            'activeMenu' => 'operator-monitor',
            'adminSiteName' => AdminWeb::siteName(),
            'operators' => $operators,
            'globalStats' => $globalStats,
        ]);
    }

    public function detail(int $adminId): View
    {
        $admin = Admin::query()->whereKey($adminId)->firstOrFail();

        $workspaces = Workspace::query()
            ->where('owner_admin_id', (int) $admin->id)
            ->orWhereHas('operators', fn ($q) => $q->where('admin_id', (int) $admin->id))
            ->withCount([
                'assignments as task_count' => fn ($q) => $q->where('assignable_type', \App\Models\Task::class),
                'assignments as article_count' => fn ($q) => $q->where('assignable_type', \App\Models\Article::class),
            ])
            ->orderByDesc('last_activity_at')
            ->get();

        return view('admin.operator-monitor.detail', [
            'pageTitle' => $admin->name.' - 运营详情',
            'activeMenu' => 'operator-monitor',
            'adminSiteName' => AdminWeb::siteName(),
            'operator' => $admin,
            'workspaces' => $workspaces,
        ]);
    }
}
