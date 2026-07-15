<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\GeoFlow\PlatformAccountService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformAccountController extends Controller
{
    public function __construct(
        private readonly PlatformAccountService $platformAccountService,
    ) {}

    public function index(): View
    {
        $wsQuery = Workspace::query()
            ->where('status', 'active')
            ->orderBy('name');
        $this->scopeByOperatorWorkspaces($wsQuery, Workspace::class);
        $workspaces = $wsQuery->get();

        return view('admin.workspaces.index', [
            'pageTitle' => '平台账号管理',
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'workspaces' => $workspaces,
            'isSuperAdmin' => true,
        ]);
    }

    public function show(int $workspaceId): View|RedirectResponse
    {
        $this->authorizeWorkspaceAccess($workspaceId);

        $workspace = Workspace::query()->whereKey($workspaceId)->first();
        if (! $workspace) {
            return redirect()->route('admin.workspaces.index')->withErrors('工作空间不存在');
        }

        $platforms = $this->platformAccountService->listForWorkspace($workspaceId);

        return view('admin.workspaces.show', [
            'pageTitle' => '平台账号 - '.$workspace->name,
            'activeMenu' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'workspace' => $workspace,
            'platforms' => $platforms,
            'stats' => [],
            'tasks' => collect(),
            'articles' => collect(),
            'clientPortalUrl' => $workspace->clientPortalUrl(),
        ]);
    }

    public function revoke(int $workspaceId, string $platformKey): RedirectResponse
    {
        $this->authorizeWorkspaceAccess($workspaceId);

        $this->platformAccountService->revokeCredential($workspaceId, $platformKey);

        return back()->with('message', '平台连接已撤销');
    }
}
