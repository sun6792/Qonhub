<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentPublisherAccount;
use App\Models\EnterpriseAnchorCertification;
use App\Models\EnterpriseProfile;
use App\Models\Workspace;
use App\Services\GeoFlow\EnterpriseAnchorService;
use App\Services\GeoFlow\PlatformSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * RPA 引擎云端同步控制器。
 *
 * 负责本地运营助手与 Laravel 后端的双向通信。
 * 路由由 rpa.auth 中间件保护（X-Api-Key 认证）。
 *
 * 安全加固（v2.6.1+）：
 *   - Tier 1: localhost-only 模式（GEOFLOW_RPA_LOCALHOST_ONLY=true，默认开启）
 *   - Tier 2: operator 身份绑定（X-Operator-Token header → Admin API Token → 按 workspace 隔离）
 */
class RpaSyncController extends Controller
{
    // ── 安全基础设施 ─────────────────────────────────────

    /**
     * 检查请求是否来自本地回环地址。
     * 由 GEOFLOW_RPA_LOCALHOST_ONLY 环境变量控制，默认开启。
     */
    private function guardLocalhost(Request $request): void
    {
        if (! config('geoflow.rpa_localhost_only', true)) {
            return;
        }

        $remoteIp = $request->ip();
        if (! in_array($remoteIp, ['127.0.0.1', '::1', 'localhost'], true)) {
            Log::warning('RPA: non-localhost access denied', [
                'remote_ip' => $remoteIp,
                'path' => $request->path(),
            ]);
            abort(403, 'RPA API 仅限本地访问（GEOFLOW_RPA_LOCALHOST_ONLY=true）');
        }
    }

    /**
     * 解析 RPA Operator 身份（通过 X-Operator-Token header）。
     * 返回该 operator 有权访问的 workspace ID 列表。
     *
     * @return int[]|null  null=无身份（兼容旧版dashboard），[]=有身份但无workspace
     */
    private function resolveRpaOperatorWorkspaceIds(Request $request): ?array
    {
        $token = $request->header('X-Operator-Token');
        if (empty($token)) {
            return null; // 未提供 token，向后兼容
        }

        // 通过 Sanctum personal access token 查找 admin
        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        if (! $accessToken || $accessToken->tokenable_type !== \App\Models\Admin::class) {
            Log::warning('RPA: invalid operator token', ['token_hash' => substr(hash('sha256', $token), 0, 8)]);
            return [];
        }

        /** @var \App\Models\Admin $admin */
        $admin = $accessToken->tokenable;

        return $admin->scopedWorkspaceIds() ?? [];
    }

    /**
     * 校验 workspace 存在性，不存在则返回 404。
     */
    private function validateWorkspace(int $wsId): Workspace
    {
        $workspace = Workspace::query()->find($wsId);
        if (! $workspace) {
            abort(404, '工作空间不存在');
        }
        return $workspace;
    }

    /**
     * 校验 operator 对 workspace 的访问权限。
     * operatorWorkspaceIds 为 null 时（未提供身份）跳过，向后兼容。
     */
    private function authorizeRpaWorkspaceAccess(int $wsId, ?array $operatorWorkspaceIds): void
    {
        if ($operatorWorkspaceIds === null) {
            return; // 未提供 operator token，向后兼容
        }

        if (! in_array($wsId, $operatorWorkspaceIds, true)) {
            Log::warning('RPA: workspace access denied by operator scope', [
                'workspace_id' => $wsId,
            ]);
            abort(403, '无权访问该工作空间');
        }
    }

    // ── API 端点 ─────────────────────────────────────────

    /**
     * GET /api/v1/rpa/pending-tasks?workspace_id=N
     */
    public function pendingTasks(Request $request): JsonResponse
    {
        $this->guardLocalhost($request);
        $operatorWsIds = $this->resolveRpaOperatorWorkspaceIds($request);

        $wsId = (int) $request->query('workspace_id', 0);
        if ($wsId <= 0) {
            return response()->json(['tasks' => []]);
        }

        $this->authorizeRpaWorkspaceAccess($wsId, $operatorWsIds);
        $workspace = $this->validateWorkspace($wsId);

        $profile = EnterpriseProfile::query()->where('workspace_id', $wsId)->first();
        if (! $profile) {
            return response()->json(['tasks' => []]);
        }

        $platforms = EnterpriseAnchorService::anchorPlatforms();
        $existingCerts = EnterpriseAnchorCertification::query()
            ->where('enterprise_profile_id', (int) $profile->id)
            ->get()
            ->keyBy('anchor_platform_key');

        $tasks = [];
        foreach ($platforms as $key => $info) {
            $cert = $existingCerts->get($key);
            if ($cert && $cert->isCertified() && ! $cert->isExpired()) {
                continue;
            }
            if (empty($info['supports_rpa']) && ($info['coverage'] ?? 'manual') !== 'rpa') {
                continue;
            }
            $tasks[] = [
                'platform_key' => $key,
                'platform_name' => $info['name'],
                'platform' => $key,
                'action' => 'register_and_certify',
                'description' => $info['description'] ?? '企业认证注册',
                'cert_required' => $info['cert_required'] ?? '企业营业执照',
            ];
        }

        return response()->json(['tasks' => $tasks, 'workspace_id' => $wsId, 'workspace_name' => $workspace->name]);
    }

    /**
     * POST /api/v1/rpa/report
     */
    public function report(Request $request): JsonResponse
    {
        $this->guardLocalhost($request);
        $operatorWsIds = $this->resolveRpaOperatorWorkspaceIds($request);

        $payload = $request->validate([
            'task_id' => ['nullable', 'string'],
            'workspace_id' => ['required', 'integer'],
            'platform' => ['required', 'string'],
            'success' => ['required', 'boolean'],
            'shop_url' => ['nullable', 'string', 'max:500'],
            'account_id' => ['nullable', 'string', 'max:100'],
            'error' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->authorizeRpaWorkspaceAccess((int) $payload['workspace_id'], $operatorWsIds);
        $this->validateWorkspace((int) $payload['workspace_id']);

        $profile = EnterpriseProfile::query()
            ->where('workspace_id', (int) $payload['workspace_id'])
            ->first();

        if (! $profile) {
            return response()->json(['success' => false, 'error' => '企业档案不存在']);
        }

        $platformKey = $payload['platform'];
        $platforms = EnterpriseAnchorService::allAnchorPlatforms();
        $platformInfo = $platforms[$platformKey] ?? null;

        if (! $platformInfo) {
            return response()->json(['success' => false, 'error' => '未知平台']);
        }

        try {
            if ($payload['success']) {
                $cert = EnterpriseAnchorCertification::query()->firstOrCreate(
                    ['enterprise_profile_id' => (int) $profile->id, 'anchor_platform_key' => $platformKey],
                    [
                        'certification_status' => 'certified',
                        'certified_at' => now(),
                        'platform_page_url' => $payload['shop_url'] ?? '',
                        'platform_account_id' => $payload['account_id'] ?? '',
                    ]
                );
                if (! $cert->wasRecentlyCreated) {
                    $cert->forceFill([
                        'certification_status' => 'certified',
                        'certified_at' => now(),
                        'platform_page_url' => $payload['shop_url'] ?: $cert->platform_page_url,
                        'platform_account_id' => $payload['account_id'] ?: $cert->platform_account_id,
                    ])->save();
                }

                app(PlatformSyncService::class)->syncBinding((int) $payload['workspace_id'], [
                    'platform_key' => $platformKey,
                    'platform_name' => $platformInfo['name'] ?? $platformKey,
                    'source' => 'rpa_engine',
                ]);

                Log::info('RPA sync: certification + platform binding synced', [
                    'workspace_id' => $payload['workspace_id'],
                    'platform' => $platformKey,
                    'cert_id' => $cert->id,
                ]);
            } else {
                Log::warning('RPA sync: task failed', [
                    'workspace_id' => $payload['workspace_id'],
                    'platform' => $platformKey,
                    'error' => $payload['error'] ?? 'unknown',
                ]);
            }

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            Log::error('RPA sync: report processing error', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/v1/rpa/articles?workspace_id=N
     */
    public function articles(Request $request): JsonResponse
    {
        $this->guardLocalhost($request);
        $operatorWsIds = $this->resolveRpaOperatorWorkspaceIds($request);

        $wsId = (int) $request->query('workspace_id', 0);
        if ($wsId > 0) {
            $this->authorizeRpaWorkspaceAccess($wsId, $operatorWsIds);
            $this->validateWorkspace($wsId);
        }

        $query = \App\Models\Article::query()
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->limit(30);

        if ($wsId > 0) {
            $query->whereIn('id', function ($sub) use ($wsId) {
                $sub->select('assignable_id')
                    ->from('workspace_assignments')
                    ->where('assignable_type', \App\Models\Article::class)
                    ->where('workspace_id', $wsId);
            });
        }

        $articles = $query->get(['id', 'title', 'excerpt', 'published_at']);

        return response()->json([
            'workspace_id' => $wsId,
            'articles' => $articles->map(fn ($a) => [
                'id' => (int) $a->id,
                'title' => $a->title,
                'excerpt' => mb_substr((string) ($a->excerpt ?? ''), 0, 80),
                'published_at' => $a->published_at?->format('Y-m-d H:i'),
            ])->all(),
        ]);
    }

    /**
     * GET /api/v1/rpa/articles/{id}
     */
    public function articleDetail(Request $request, int $id): JsonResponse
    {
        $this->guardLocalhost($request);
        $operatorWsIds = $this->resolveRpaOperatorWorkspaceIds($request);

        $article = \App\Models\Article::query()->find($id);
        if (! $article) {
            return response()->json(['error' => '文章不存在'], 404);
        }

        // 检查文章所属 workspace 是否在 operator 权限范围内
        if ($operatorWsIds !== null) {
            $articleWsIds = \Illuminate\Support\Facades\DB::table('workspace_assignments')
                ->where('assignable_type', \App\Models\Article::class)
                ->where('assignable_id', $id)
                ->pluck('workspace_id')
                ->map(fn ($v) => (int) $v)
                ->toArray();

            $allowed = ! empty(array_intersect($articleWsIds, $operatorWsIds));
            if (! $allowed && $articleWsIds !== []) {
                Log::warning('RPA: article access denied by operator scope', ['article_id' => $id]);
                abort(403, '无权访问该文章');
            }
        }

        return response()->json([
            'id' => (int) $article->id,
            'title' => $article->title,
            'content' => $article->content,
            'excerpt' => $article->excerpt,
        ]);
    }

    /**
     * GET /api/v1/rpa/client-platforms?workspace_id=N
     */
    public function clientPlatforms(Request $request): JsonResponse
    {
        $this->guardLocalhost($request);
        $operatorWsIds = $this->resolveRpaOperatorWorkspaceIds($request);

        $wsId = (int) $request->query('workspace_id', 0);
        if ($wsId <= 0) {
            return response()->json(['platforms' => []]);
        }

        $this->authorizeRpaWorkspaceAccess($wsId, $operatorWsIds);
        $this->validateWorkspace($wsId);

        $sync = app(PlatformSyncService::class);
        $platforms = $sync->getUnifiedStatus($wsId);

        return response()->json([
            'workspace_id' => $wsId,
            'platforms' => $platforms,
        ]);
    }

    /**
     * GET /api/v1/rpa/credentials?workspace_id=N
     *
     * 凭证经 AES-256-CBC 解密后通过本地 API 传输。
     *
     * 安全：仅允许 localhost（Tier 1）+ operator 绑定（Tier 2）。
     */
    public function credentials(Request $request): JsonResponse
    {
        $this->guardLocalhost($request);
        $operatorWsIds = $this->resolveRpaOperatorWorkspaceIds($request);

        $wsId = (int) $request->query('workspace_id', 0);
        if ($wsId <= 0) {
            return response()->json(['credentials' => []]);
        }

        $this->authorizeRpaWorkspaceAccess($wsId, $operatorWsIds);
        $this->validateWorkspace($wsId);

        Log::warning('RPA: credentials accessed (sensitive)', [
            'workspace_id' => $wsId,
            'remote_ip' => $request->ip(),
        ]);

        $crypto = app(\App\Support\GeoFlow\ApiKeyCrypto::class);
        $accounts = \App\Models\ClientPlatformAccount::query()
            ->where('workspace_id', $wsId)
            ->where('status', 'active')
            ->get();

        $result = [];
        foreach ($accounts as $a) {
            $cred = null;
            if (! empty($a->credential_ciphertext)) {
                try {
                    $cred = $crypto->decrypt($a->credential_ciphertext);
                } catch (\Throwable $e) {
                    $cred = null;
                }
            }
            $result[] = [
                'platform_key' => $a->platform_key,
                'account_name' => $a->platform_account_name,
                'credential' => $cred,
                'last_verified_at' => $a->last_verified_at?->toIso8601String(),
            ];
        }

        return response()->json(['credentials' => $result]);
    }

    /**
     * GET /api/v1/rpa/distribution-channels?workspace_id=N
     */
    public function distributionChannels(Request $request): JsonResponse
    {
        $this->guardLocalhost($request);
        $operatorWsIds = $this->resolveRpaOperatorWorkspaceIds($request);

        $wsId = (int) $request->query('workspace_id', 0);
        if ($wsId > 0) {
            $this->authorizeRpaWorkspaceAccess($wsId, $operatorWsIds);
        }

        $channels = [];

        $apiChannels = \App\Models\DistributionChannel::query()
            ->where('status', 'active')
            ->get(['id', 'name', 'domain', 'channel_type']);
        foreach ($apiChannels as $ch) {
            $channels[] = [
                'key' => 'api_' . $ch->channel_type,
                'name' => $ch->name ?: ($ch->domain ?: $ch->channel_type),
                'type' => 'api',
                'channel_id' => (int) $ch->id,
            ];
        }

        $rpaPlatforms = [
            ['key' => 'toutiao_publish', 'name' => '头条号', 'type' => 'rpa'],
            ['key' => 'baijiahao_publish', 'name' => '百家号', 'type' => 'rpa'],
            ['key' => 'xiaohongshu_publish', 'name' => '小红书', 'type' => 'rpa'],
        ];
        $channels = array_merge($channels, $rpaPlatforms);

        return response()->json(['channels' => $channels]);
    }

    /**
     * GET /api/v1/rpa/my-workspaces
     *
     * 有 X-Operator-Token 时只返回该运营绑定的 workspace；
     * 无 token 时兜底返回全部（向后兼容旧版 dashboard）。
     */
    public function myWorkspaces(Request $request): JsonResponse
    {
        $this->guardLocalhost($request);
        $operatorWsIds = $this->resolveRpaOperatorWorkspaceIds($request);

        $query = Workspace::query()->select(['id', 'name', 'slug'])
            ->where('status', 'active')
            ->orderBy('name');

        // Tier 2: operator 身份绑定 → 只返回该运营的 workspace
        if ($operatorWsIds !== null) {
            if ($operatorWsIds === []) {
                return response()->json(['workspaces' => []]);
            }
            $query->whereIn('id', $operatorWsIds);
        }

        $workspaces = $query->get();

        return response()->json([
            'workspaces' => $workspaces->map(fn ($w) => [
                'id' => (int) $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
            ])->all(),
        ]);
    }

    /**
     * POST /api/v1/rpa/bulk-distribute
     * Body: { workspace_id, platform, article_ids[] }
     */
    public function bulkDistribute(Request $request): JsonResponse
    {
        $this->guardLocalhost($request);
        $operatorWsIds = $this->resolveRpaOperatorWorkspaceIds($request);

        $payload = $request->validate([
            'workspace_id' => ['required', 'integer', 'min:1'],
            'platform' => ['required', 'string'],
            'article_ids' => ['required', 'array', 'min:1'],
            'article_ids.*' => ['integer'],
        ]);

        $wsId = (int) $payload['workspace_id'];
        $this->authorizeRpaWorkspaceAccess($wsId, $operatorWsIds);
        $workspace = $this->validateWorkspace($wsId);

        try {
            $publishService = app(\App\Services\GeoFlow\Publishing\ContentPublishService::class);
            $task = $publishService->createPublishTask(
                workspace: $workspace,
                articleIds: $payload['article_ids'],
                platformKeys: [$payload['platform']],
                options: [
                    'task_name' => '运营助手批量分发 - ' . $payload['platform'] . ' - ' . now()->format('H:i'),
                    'use_smart_scheduling' => true,
                    'use_content_rewrite' => true,
                    'rewrite_mode' => 'per_platform',
                ],
            );
            $publishService->dispatchPublishTask($task);

            return response()->json([
                'ok' => true,
                'task_id' => (int) $task->id,
                'total_jobs' => (int) $task->total_jobs,
                'message' => "已创建分发任务，共 {$task->total_jobs} 个作业",
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/scout/report — Scout脚本上报AI收录结果。
     * Body: { workspace_id, platform, results: [{url, domain, title, excerpt}] }
     * scout_and_save.cjs 执行完成后自动调用此端点将数据写入 ai_cited_sources 表。
     */
    public function scoutReport(Request $request): JsonResponse
    {
        $this->guardLocalhost($request);
        $operatorWsIds = $this->resolveRpaOperatorWorkspaceIds($request);

        $payload = $request->validate([
            'workspace_id' => ['required', 'integer', 'min:1'],
            'platform' => ['required', 'string'],
            'results' => ['required', 'array'],
            'results.*.url' => ['required', 'string'],
            'results.*.domain' => ['nullable', 'string'],
            'results.*.title' => ['nullable', 'string'],
            'results.*.excerpt' => ['nullable', 'string'],
        ]);

        $wsId = (int) $payload['workspace_id'];
        $this->authorizeRpaWorkspaceAccess($wsId, $operatorWsIds);
        $this->validateWorkspace($wsId);

        $platform = $payload['platform'];
        $inserted = 0;

        foreach ($payload['results'] as $r) {
            try {
                \App\Models\AiCitedSource::updateOrCreate(
                    ['workspace_id' => $wsId, 'url' => $r['url']],
                    [
                        'ai_platform' => $platform,
                        'domain' => $r['domain'] ?? parse_url($r['url'], PHP_URL_HOST),
                        'title' => $r['title'] ?? null,
                        'excerpt' => $r['excerpt'] ?? null,
                    ]
                );
                $inserted++;
            } catch (\Throwable) { /* skip duplicate */ }
        }

        Log::info('Scout report ingested', [
            'workspace_id' => $wsId,
            'platform' => $platform,
            'inserted' => $inserted,
        ]);

        return response()->json(['ok' => true, 'inserted' => $inserted]);
    }
}
