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

        // 平台资产聚合（B2B认证 + 发布授权 — 统一客户端视图）
        $platformAssets = [
            'b2b_certified' => 0,
            'b2b_total' => 0,
            'publish_authorized' => 0,
            'publish_total' => 0,
            'recent_publish_tasks' => [],
        ];

        // B2B 认证
        if ($anchorData) {
            $platformAssets['b2b_certified'] = $anchorData['certified_count'];
            $platformAssets['b2b_total'] = $anchorData['total_count'];
        }

        // 发布平台授权
        $publisherAccounts = \App\Models\ContentPublisherAccount::query()
            ->where('workspace_id', (int) $workspace->id)
            ->where('status', 'active')
            ->get();
        $platformAssets['publish_authorized'] = $publisherAccounts->count();
        $platformAssets['publish_total'] = $connectionStats['total'] ?? 0;

        // 最近发布任务
        $platformAssets['recent_publish_tasks'] = \App\Models\ContentPublishTask::query()
            ->where('workspace_id', (int) $workspace->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'name' => $t->task_name,
                'status' => $t->status,
                'progress' => $t->total_jobs > 0 ? round($t->completed_jobs / $t->total_jobs * 100) : 0,
                'created_at' => $t->created_at?->format('m-d H:i'),
            ])
            ->all();

        // v2.8.0: 最近的智能体执行记录
        $recentExecutions = \App\Models\AgentExecution::query()
            ->where('workspace_id', (int) $workspace->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'state' => $e->current_state,
                'geo_score' => $e->content_output['geo_score'] ?? 0,
                'geo_grade' => $e->content_output['geo_grade'] ?? '-',
                'state_label' => [
                    'idle' => '等待中', 'scouting' => '侦察中', 'planning' => '策略规划',
                    'writing' => '内容生产', 'deploying' => '分发执行', 'reviewing' => '复盘分析',
                    'completed' => '已完成', 'failed' => '失败',
                ][$e->current_state] ?? $e->current_state,
                'started_at' => $e->started_at?->format('m-d H:i'),
            ])
            ->all();

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
            'platformAssets' => $platformAssets,
            'recentExecutions' => $recentExecutions,
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

    /**
     * v2.6.0 快照凭证独立页面。
     * GET /client/snapshot/{checkId}
     */
    public function snapshot(int $checkId): View|RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) return redirect()->route('client.login');

        $check = \App\Models\AiVisibilityCheck::where('workspace_id', (int) $client->workspace_id)
            ->whereKey($checkId)->firstOrFail();

        $platforms = \App\Services\GeoFlow\AiVisibilityService::AI_PLATFORMS;
        $info = $platforms[$check->ai_platform] ?? null;

        return view('client.snapshot', [
            'workspace' => Workspace::query()->whereKey((int) $client->workspace_id)->firstOrFail(),
            'check' => $check,
            'platformInfo' => $info,
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
        $wid = (int) $workspace->id;

        // 对话快照 + 引用来源
        $snapshotData = $this->aiVisibilityService->clientSnapshots($wid);

        return view('client.ai-visibility', [
            'workspace' => $workspace,
            'overview' => $this->aiVisibilityService->dashboardOverview($wid),
            'snapshots' => $snapshotData['snapshots'],
            'citedSources' => $snapshotData['cited_sources'],
            'snapshotData' => $snapshotData,  // 完整数据（含 review_recommendations）
            'top5' => $this->aiVisibilityService->brandTop5Share($wid),
            'visibilityData' => $this->aiVisibilityService->clientVisibilityData($wid),
            'runningWords' => $this->aiVisibilityService->runningWords($wid),
            'collectedWords' => $this->aiVisibilityService->collectedWords($wid),
        ]);
    }

    /**
     * AI搜索竞争力分析报告。
     * GET /client/competitiveness
     */
    public function competitiveness(Request $request): View|RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $workspace = Workspace::query()->whereKey((int) $client->workspace_id)->firstOrFail();
        $wid = (int) $workspace->id;

        return view('client.competitiveness', [
            'workspace' => $workspace,
            'overview' => $this->aiVisibilityService->dashboardOverview($wid),
            'comparison' => $this->aiVisibilityService->brandCompare($wid),
            'top5' => $this->aiVisibilityService->brandTop5Share($wid),
            'competitors' => \App\Models\AiCompetitor::where('workspace_id', $wid)->where('status', 'active')->get(),
        ]);
    }

    /**
     * 添加竞品。
     * POST /client/competitiveness/store
     */
    public function competitorStore(Request $request): RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $payload = $request->validate([
            'brand_name' => ['required', 'string', 'max:100'],
            'brand_website' => ['nullable', 'string', 'max:500'],
        ]);

        \App\Models\AiCompetitor::create([
            'workspace_id' => (int) $client->workspace_id,
            'brand_name' => $payload['brand_name'],
            'brand_website' => $payload['brand_website'] ?? null,
        ]);

        return redirect()->route('client.competitiveness')->with('success', '竞品已添加');
    }

    /**
     * 删除竞品。
     * POST /client/competitiveness/delete/{id}
     */
    public function competitorDelete(int $id): RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        \App\Models\AiCompetitor::query()
            ->where('id', $id)
            ->where('workspace_id', (int) $client->workspace_id)
            ->delete();

        return redirect()->route('client.competitiveness')->with('success', '竞品已删除');
    }

    /**
     * 客户端保存企业资料（B2B注册必填）
     * POST /client/enterprise-profile/save
     */
    public function enterpriseProfileSave(Request $request): RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $payload = $request->validate([
            'company_full_name' => ['required', 'string', 'max:200'],
            'unified_social_credit_code' => ['nullable', 'string', 'max:50'],
            'legal_person' => ['nullable', 'string', 'max:50'],
            'industry' => ['nullable', 'string', 'max:100'],
            'company_address' => ['nullable', 'string', 'max:300'],
            'company_phone' => ['nullable', 'string', 'max:30'],
            'business_scope' => ['nullable', 'string', 'max:2000'],
            'products_services' => ['nullable', 'string', 'max:500'],
        ]);

        $profile = \App\Models\EnterpriseProfile::query()
            ->firstOrNew(['workspace_id' => (int) $client->workspace_id]);

        $profile->forceFill([
            'company_full_name' => $payload['company_full_name'],
            'unified_social_credit_code' => $payload['unified_social_credit_code'] ?? null,
            'legal_person' => $payload['legal_person'] ?? null,
            'industry' => $payload['industry'] ?? null,
            'company_address' => $payload['company_address'] ?? null,
            'company_phone' => $payload['company_phone'] ?? null,
            'business_scope' => $payload['business_scope'] ?? null,
            'products_services' => $payload['products_services'] ?? null,
        ])->save();

        return redirect()->route('client.content-publish.certify')
            ->with('success', '企业资料已保存，B2B注册进度已更新');
    }

    /**
     * P1 客户端提交内容需求
     * POST /client/content-request
     */
    public function contentRequestStore(Request $request): RedirectResponse
    {
        $client = Auth::guard('client')->user();
        if (! $client) {
            return redirect()->route('client.login');
        }

        $payload = $request->validate([
            'topic' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        \Illuminate\Support\Facades\Log::info('Client content request', [
            'workspace_id' => (int) $client->workspace_id,
            'client' => $client->username,
            'topic' => $payload['topic'],
            'notes' => $payload['notes'] ?? '',
        ]);

        return redirect()->route('client.dashboard')
            ->with('success', '内容需求已提交，运营团队将尽快处理');
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
            'credential' => ['nullable', 'string', 'max:5000'],
        ]);

        // 统一三处同步：客户端授权 → 发布管道 + 锚点自动创建
        app(\App\Services\GeoFlow\PlatformSyncService::class)->syncBinding($workspaceId, [
            'platform_key' => $payload['platform_key'],
            'platform_name' => $payload['platform_account_name'],
            'credential' => $payload['credential'] ?? null,
            'source' => 'client_portal',
        ]);

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

        // 同步信息锚点：解绑=取消认证
        $profile = \App\Models\EnterpriseProfile::where('workspace_id', $workspaceId)->first();
        if ($profile) {
            \App\Models\EnterpriseAnchorCertification::where('enterprise_profile_id', (int)$profile->id)
                ->where('anchor_platform_key', $payload['platform_key'])
                ->update(['certification_status' => 'pending']);
        }

        // 同步清除 RPA 缓存：解绑后运营助手不再显示已绑定
        try {
            $rpaUrl = rtrim((string) config('geoflow.rpa_engine_url', 'http://127.0.0.1:9901'), '/');
            $apiKey = (string) config('geoflow.rpa_engine_api_key');
            \Illuminate\Support\Facades\Http::timeout(5)
                ->withHeaders(['X-Api-Key' => $apiKey, 'Content-Type' => 'application/json'])
                ->post($rpaUrl . '/api/cache/clear', [
                    'workspace_id' => (string) $workspaceId,
                    'platform_key' => $payload['platform_key'],
                ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('RPA cache clear failed on unbind: ' . $e->getMessage());
        }

        return redirect()->route('client.platforms')->with('message', '已解绑，锚点+缓存已同步清除');
    }

    /**
     * 客户智能体报告页 — 展示五智能体执行结果和 AI 收录状态。
     */
    public function agentReport(int $executionId): View|RedirectResponse
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

        $execution = \App\Models\AgentExecution::query()
            ->where('workspace_id', (int) $workspace->id)
            ->findOrFail($executionId);

        // 识别 AI 平台收录状态（红/黄/绿）
        $snapshots = $execution->scout_output['live_snapshots'] ?? [];
        $platformStatuses = [];
        foreach ($snapshots as $s) {
            $name = $s['name'] ?? $s['provider'] ?? '未知';
            $score = $s['score'] ?? 0;
            $platformStatuses[] = [
                'name' => $name,
                'mentioned' => $s['mentioned'] ?? false,
                'score' => $score,
                'status' => $score >= 70 ? 'green' : ($score >= 30 ? 'yellow' : 'red'),
                'preview' => mb_substr($s['preview'] ?? '', 0, 120),
            ];
        }

        // Review 推荐
        $reviewOutput = $execution->review_output ?? [];
        $recommendations = $reviewOutput['recommendations'] ?? [];
        $geoScore = $execution->content_output['geo_score'] ?? 0;
        $geoGrade = $execution->content_output['geo_grade'] ?? 'N/A';

        return view('client.agent-report', [
            'workspace' => $workspace,
            'execution' => $execution,
            'platformStatuses' => $platformStatuses,
            'recommendations' => $recommendations,
            'geoScore' => $geoScore,
            'geoGrade' => $geoGrade,
            'summary' => $reviewOutput['summary'] ?? [],
            'iteration' => (int) ($execution->input_data['iteration'] ?? 0),
        ]);
    }
}
