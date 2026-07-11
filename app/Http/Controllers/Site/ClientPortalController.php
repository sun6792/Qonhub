<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ClientUser;
use App\Models\Workspace;
use App\Services\GeoFlow\AiVisibilityService;
use App\Services\GeoFlow\PlatformAccountService;
use App\Services\GeoFlow\WorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ClientPortalController extends Controller
{
    public function __construct(
        private readonly WorkspaceService $workspaceService,
        private readonly PlatformAccountService $platformAccountService,
        private readonly AiVisibilityService $aiVisibilityService,
    ) {}

    public function dashboard(Request $request): View|RedirectResponse
    {
        /** @var ClientUser|null $client */
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $workspace = Workspace::query()->whereKey((int) $client->workspace_id)->first();
        if (! $workspace || ! $workspace->isActive()) {
            Auth::guard('client')->logout();
            return redirect()->route('client.login')->withErrors('您的账号已被暂停，请联系管理员');
        }

        $articleIds = $this->workspaceService->assignedIds((int) $workspace->id, Article::class);

        $articles = collect();
        if ($articleIds !== []) {
            $articles = Article::query()
                ->whereIn('id', $articleIds)
                ->where('status', 'published')
                ->orderByDesc('published_at')
                ->limit(10)
                ->get();
        }

        $totalArticles = count($articleIds);
        $publishedCount = ($articleIds !== [])
            ? Article::query()->whereIn('id', $articleIds)->where('status', 'published')->count()
            : 0;
        $thisMonthCount = ($articleIds !== [])
            ? Article::query()->whereIn('id', $articleIds)->where('status', 'published')
                ->whereMonth('published_at', now()->month)->count()
            : 0;

        $visibilityData = $this->aiVisibilityService->clientVisibilityData((int) $workspace->id);
        $platforms = $this->platformAccountService->listForWorkspace((int) $workspace->id);
        $connectionStats = $this->platformAccountService->connectionStats((int) $workspace->id);

        // B2B 信息锚点（只取结果给客户看，不暴露管理细节）
        $anchorData = null;
        $profile = $workspace->enterpriseProfile;
        if ($profile && $profile->company_full_name) {
            $anchorService = app(\App\Services\GeoFlow\EnterpriseAnchorService::class);
            $summary = $anchorService->certificationSummary($profile);
            $coverage = $anchorService->llmCoverageReport($profile);
            $anchorData = [
                'certified_count' => $summary['certified'],
                'total_count' => $summary['total'],
                'certified_platforms' => $summary['platforms']
                    ->where('status', 'certified')
                    ->map(fn($p) => $p['platform_info']['name'])
                    ->values()
                    ->all(),
                'llm_coverage' => $coverage['cited_by_llms'],
                'nap_ok' => $profile->nap_consistency_checked,
            ];
        }

        // 发布统计（最近7天）
        $publishStats = [
            'total_tasks' => \App\Models\ContentPublishTask::query()
                ->where('workspace_id', (int) $workspace->id)
                ->count(),
            'recent_success' => \App\Models\ContentPublishResult::query()
                ->where('workspace_id', (int) $workspace->id)
                ->where('status', 'success')
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            'recent_total' => \App\Models\ContentPublishResult::query()
                ->where('workspace_id', (int) $workspace->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
        ];

        return view('client.dashboard', [
            'workspace' => $workspace,
            'articles' => $articles,
            'totalArticles' => $totalArticles,
            'publishedCount' => $publishedCount,
            'thisMonthCount' => $thisMonthCount,
            'visibilityData' => $visibilityData,
            'platforms' => $platforms,
            'connectionStats' => $connectionStats,
            'anchorData' => $anchorData,
            'publishStats' => $publishStats,
        ]);
    }

    public function articles(Request $request): View|RedirectResponse
    {
        /** @var ClientUser|null $client */
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $workspace = Workspace::query()->whereKey((int) $client->workspace_id)->firstOrFail();

        $articleIds = $this->workspaceService->assignedIds((int) $workspace->id, Article::class);
        $articles = Article::query()
            ->whereIn('id', $articleIds ?: [0])
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->paginate(20);

        return view('client.articles', [
            'workspace' => $workspace,
            'articles' => $articles,
        ]);
    }

    public function aiVisibility(Request $request): View|RedirectResponse
    {
        /** @var ClientUser|null $client */
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $workspace = Workspace::query()->whereKey((int) $client->workspace_id)->firstOrFail();
        $visibilityData = $this->aiVisibilityService->clientVisibilityData((int) $workspace->id);

        return view('client.ai-visibility', [
            'workspace' => $workspace,
            'visibilityData' => $visibilityData,
        ]);
    }
}
