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

        return view('client.dashboard', [
            'workspace' => $workspace,
            'articles' => $articles,
            'totalArticles' => $totalArticles,
            'publishedCount' => $publishedCount,
            'thisMonthCount' => $thisMonthCount,
            'visibilityData' => $visibilityData,
            'platforms' => $platforms,
            'connectionStats' => $connectionStats,
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
