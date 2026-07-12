<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ClientPlatformAccount;
use App\Models\ClientUser;
use App\Models\Workspace;
use App\Services\GeoFlow\AiVisibilityService;
use App\Services\GeoFlow\PlatformAccountService;
use App\Services\GeoFlow\WorkspaceService;
use App\Support\GeoFlow\ApiKeyCrypto;
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

    /**
     * [新增] 客户平台授权绑定页面
     * GET /client/platforms
     */
    public function platforms(Request $request): View|RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }
        $workspace = Workspace::query()->whereKey((int) $client->workspace_id)->firstOrFail();

        // 已绑定的平台
        $bound = ClientPlatformAccount::query()
            ->where('workspace_id', (int) $workspace->id)
            ->get();

        // 可绑定的平台列表：自媒体 + B2B
        $availablePlatforms = [
            ['key' => 'toutiao', 'name' => '头条号', 'type' => '自媒体', 'help' => '登录头条号后，复制浏览器Cookie粘贴到下方'],
            ['key' => 'baijiahao', 'name' => '百家号', 'type' => '自媒体', 'help' => '登录百家号后，复制浏览器Cookie粘贴到下方'],
            ['key' => 'xiaohongshu', 'name' => '小红书', 'type' => '自媒体', 'help' => '登录小红书创作者平台后，复制浏览器Cookie粘贴到下方'],
            ['key' => 'sohu', 'name' => '搜狐号', 'type' => '自媒体', 'help' => '登录搜狐号后，复制浏览器Cookie粘贴到下方'],
            ['key' => 'b2b168', 'name' => '八方资源网', 'type' => 'B2B', 'help' => '注册账号后填写账号密码'],
            ['key' => 'huangye88', 'name' => '黄页88', 'type' => 'B2B', 'help' => '注册账号后填写账号密码'],
            ['key' => 'shunqi', 'name' => '顺企网', 'type' => 'B2B', 'help' => '注册账号后填写账号密码'],
        ];

        return view('client.platforms', [
            'workspace' => $workspace,
            'bound' => $bound->keyBy('platform_key'),
            'availablePlatforms' => $availablePlatforms,
        ]);
    }

    /**
     * [新增] 客户提交平台绑定
     * POST /client/platforms/bind
     */
    public function platformStore(Request $request): RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }
        $workspaceId = (int) $client->workspace_id;

        $payload = $request->validate([
            'platform_key' => ['required', 'string', 'max:50'],
            'platform_account_name' => ['required', 'string', 'max:200'],
            'credential' => ['required', 'string', 'max:5000'],
        ]);

        $crypto = app(ApiKeyCrypto::class);
        ClientPlatformAccount::query()->updateOrCreate(
            ['workspace_id' => $workspaceId, 'platform_key' => $payload['platform_key']],
            [
                'platform_account_name' => $payload['platform_account_name'],
                'credential_ciphertext' => $crypto->encrypt($payload['credential']),
                'status' => 'active',
                'last_verified_at' => now(),
            ]
        );

        return redirect()->route('client.platforms')->with('message', '平台授权绑定成功！');
    }

    /**
     * [新增] 客户解绑平台
     * POST /client/platforms/unbind
     */
    public function platformUnbind(Request $request): RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }
        $workspaceId = (int) $client->workspace_id;

        $payload = $request->validate([
            'platform_key' => ['required', 'string', 'max:50'],
        ]);

        ClientPlatformAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('platform_key', $payload['platform_key'])
            ->delete();

        return redirect()->route('client.platforms')->with('message', '已解绑');
    }
}
