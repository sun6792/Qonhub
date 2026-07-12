<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentPublisherAccount;
use App\Models\EnterpriseAnchorCertification;
use App\Models\EnterpriseProfile;
use App\Models\Workspace;
use App\Services\GeoFlow\EnterpriseAnchorService;
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
                // 自动标记认证完成
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
                Log::info('RPA sync: certification auto-saved', [
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

        // 运营助手：返回全部已发布文章（运营管理所有客户）
        $articles = \App\Models\Article::query()
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->limit(30)
            ->get(['id', 'title', 'excerpt', 'published_at']);

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
        $accounts = \App\Models\ClientPlatformAccount::query()
            ->where('workspace_id', $wsId)
            ->where('status', 'active')
            ->get(['platform_key', 'platform_account_name', 'status', 'last_verified_at']);

        return response()->json([
            'workspace_id' => $wsId,
            'platforms' => $accounts->map(fn($a) => [
                'key' => $a->platform_key,
                'name' => $a->platform_account_name ?: $a->platform_key,
                'status' => $a->status,
                'has_cache' => file_exists(storage_path("rpa/states/{$wsId}/{$a->platform_key}.json")),
            ])->all(),
        ]);
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
}
