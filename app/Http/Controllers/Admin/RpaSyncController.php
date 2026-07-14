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
 * RPA 引擎云端同步控制器 [新增]
 *
 * 负责本地运营助手与 Laravel 后端的双向通信：
 *   - 本地助手拉取待执行任务
 *   - 本地助手上报执行结果
 *   - 验证码回调同步
 */
class RpaSyncController extends Controller
{
    /**
     * GET /api/v1/rpa/pending-tasks
     * 本地助手轮询：返回指定 workspace 下的待认证 B2B 平台。
     */
    public function pendingTasks(Request $request): JsonResponse
    {
        $wsId = (int) $request->query('workspace_id', 0);
        if ($wsId <= 0) {
            return response()->json(['tasks' => []]);
        }

        $workspace = Workspace::query()->find($wsId);
        if (! $workspace) {
            return response()->json(['tasks' => []]);
        }

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
            // 只返回未认证或已过期的平台
            if ($cert && $cert->isCertified() && ! $cert->isExpired()) {
                continue;
            }
            // 只返回有 RPA 脚本的平台
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
     * 本地助手执行完成后上报结果，自动同步锚点状态。
     */
    public function report(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'task_id' => ['nullable', 'string'],
            'workspace_id' => ['required', 'integer'],
            'platform' => ['required', 'string'],
            'success' => ['required', 'boolean'],
            'shop_url' => ['nullable', 'string', 'max:500'],
            'account_id' => ['nullable', 'string', 'max:100'],
            'error' => ['nullable', 'string', 'max:1000'],
        ]);

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
                // 1) 自动标记锚点认证
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

                // 2) 同步 ClientPlatformAccount（RPA 登录成功后自动标记客户端可见）
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
     * [新增] GET /api/v1/rpa/articles?workspace_id=7
     * 返回指定 workspace 的已发布文章列表（用于本地助手文章分发）。
     */
    public function articles(Request $request): JsonResponse
    {
        $wsId = (int) $request->query('workspace_id', 0);

        $query = \App\Models\Article::query()
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->limit(30);

        // Workspace 隔离：只返回该客户的已发布文章
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
     * [新增] GET /api/v1/rpa/client-platforms?workspace_id=7
     * 返回该 workspace 客户已绑定的平台列表（供助手加载渠道）。
     */
    public function clientPlatforms(Request $request): JsonResponse
    {
        $wsId = (int) $request->query('workspace_id', 0);
        if ($wsId <= 0) {
            return response()->json(['platforms' => []]);
        }

        // 统一四态数据（DB + 缓存交叉校验）
        $sync = app(PlatformSyncService::class);
        $platforms = $sync->getUnifiedStatus($wsId);

        return response()->json([
            'workspace_id' => $wsId,
            'platforms' => $platforms,
        ]);
    }

    /**
     * [新增] GET /api/v1/rpa/credentials?workspace_id=7
     * 返回指定workspace下所有平台的解密凭证，供运营助手登录使用。
     * 凭证经 AES-256-CBC 解密后通过本地 API 传输（localhost:9901 ↔ localhost:18080，不经过公网）。
     */
    public function credentials(Request $request): JsonResponse
    {
        $wsId = (int) $request->query('workspace_id', 0);
        if ($wsId <= 0) {
            return response()->json(['credentials' => []]);
        }

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
                'credential' => $cred, // 解密后的明文（仅本地传输）
                'last_verified_at' => $a->last_verified_at?->toIso8601String(),
            ];
        }

        return response()->json(['credentials' => $result]);
    }

    /**
     * [新增] GET /api/v1/rpa/articles/{id}
     * 返回单篇文章全文（用于分发时传内容给RPA）。
     */
    public function articleDetail(int $id): JsonResponse
    {
        $article = \App\Models\Article::query()->find($id);
        if (! $article) {
            return response()->json(['error' => '文章不存在'], 404);
        }
        return response()->json([
            'id' => (int) $article->id,
            'title' => $article->title,
            'content' => $article->content,
            'excerpt' => $article->excerpt,
        ]);
    }

    /**
     * [新增] GET /api/v1/rpa/distribution-channels?workspace_id=7
     * 返回该 workspace 可用的分发渠道（API渠道 + RPA渠道）。
     */
    public function distributionChannels(Request $request): JsonResponse
    {
        $channels = [];

        // API 分发渠道（GeoFlow/WP/HTTP）
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

        // RPA 自媒体渠道
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
     * 返回当前运营名下的 workspace 列表（用于助手下拉框）。
     */
    public function myWorkspaces(Request $request): JsonResponse
    {
        // 运营助手 dashboard 无 admin session，返回全部 workspace
        $workspaces = Workspace::query()->select(['id', 'name', 'slug'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return response()->json([
            'workspaces' => $workspaces->map(fn ($w) => [
                'id' => (int) $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
            ])->all(),
        ]);
    }

    /**
     * POST /api/v1/rpa/bulk-distribute — P0 运营助手批量分发
     * Body: { workspace_id, platform, article_ids[] }
     * 自动创建 ContentPublishTask → 入队 distribution 队列
     */
    public function bulkDistribute(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'workspace_id' => ['required', 'integer', 'min:1'],
            'platform' => ['required', 'string'],
            'article_ids' => ['required', 'array', 'min:1'],
            'article_ids.*' => ['integer'],
        ]);

        $wsId = (int) $payload['workspace_id'];
        $workspace = Workspace::query()->find($wsId);
        if (! $workspace) {
            return response()->json(['ok' => false, 'error' => '工作空间不存在'], 404);
        }

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
}
