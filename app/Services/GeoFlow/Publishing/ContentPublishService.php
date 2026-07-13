<?php

namespace App\Services\GeoFlow\Publishing;

use App\Jobs\ProcessContentPublishJob;
use App\Models\Article;
use App\Models\ContentPublishResult;
use App\Models\ContentPublishTask;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * 统一内容发布编排服务。
 *
 * 作为「一键发布中心」的核心入口，接收文章+平台选择，
 * 创建发布任务、拆分发布作业、入队执行。
 *
 * 复用现有 ContentArmory 弹药库作为内容入口，
 * 复用现有 distribution 队列 + Horizon 调度体系。
 */
class ContentPublishService
{
    public function __construct(
        private readonly AccountPoolService $accountPool,
    ) {}

    /**
     * 创建一键发布任务。
     *
     * @param  int[]  $articleIds   文章 ID 列表
     * @param  string[]  $platformKeys  平台 key 列表
     * @param  array  $options  发布选项（smart_scheduling, content_rewrite, min_interval 等）
     */
    public function createPublishTask(
        Workspace $workspace,
        array $articleIds,
        array $platformKeys,
        array $options = [],
        ?int $createdByAdminId = null,
        ?int $requestedByClientId = null,
    ): ContentPublishTask {
        $articles = Article::query()
            ->whereIn('id', $articleIds)
            ->where('status', 'published')
            ->get();

        if ($articles->isEmpty()) {
            throw new \RuntimeException('没有可发布的文章');
        }

        $totalJobs = 0;
        $platformCount = count($platformKeys);

        // 计算总分发作业数（文章 × 平台数，每个平台至少1次）
        foreach ($platformKeys as $platformKey) {
            $accounts = $this->accountPool->getAvailableAccounts((int) $workspace->id, $platformKey);
            $accountCount = max(1, $accounts->count());
            $totalJobs += count($articleIds) * $accountCount;
        }

        $task = DB::transaction(function () use (
            $workspace, $articles, $platformKeys, $totalJobs, $platformCount, $options, $createdByAdminId, $requestedByClientId
        ) {
            $task = ContentPublishTask::query()->create([
                'workspace_id' => (int) $workspace->id,
                'task_name' => $options['task_name'] ?? ('批量发布 '.now()->format('m-d H:i')),
                'status' => 'pending',
                'article_ids' => $articles->pluck('id')->all(),
                'platform_keys' => $platformKeys,
                'total_articles' => $articles->count(),
                'total_platforms' => $platformCount,
                'total_jobs' => $totalJobs,
                'avg_geo_score' => $options['avg_geo_score'] ?? null,
                'geo_score_details' => $options['geo_score_details'] ?? null,
                'use_smart_scheduling' => (bool) ($options['use_smart_scheduling'] ?? true),
                'use_content_rewrite' => (bool) ($options['use_content_rewrite'] ?? true),
                'rewrite_mode' => (string) ($options['rewrite_mode'] ?? 'per_platform'),
                'min_publish_interval_seconds' => (int) ($options['min_publish_interval_seconds'] ?? 60),
                'max_concurrent_accounts' => (int) ($options['max_concurrent_accounts'] ?? 3),
                'created_by_admin_id' => $createdByAdminId,
                'requested_by_client_user_id' => $requestedByClientId,
            ]);

            // 拆分为明细结果记录（每个 article × 每个 platform 最小一条）
            $results = [];
            foreach ($articles as $article) {
                foreach ($platformKeys as $platformKey) {
                    $accounts = $this->accountPool->getAvailableAccounts((int) $workspace->id, $platformKey);

                    if ($accounts->isEmpty()) {
                        // 无账号时创建 pending 记录，等待手动添加账号后重试
                        // 注意：publisher_account_id 设为 null，与有账号的条目保持相同列结构
                        $results[] = [
                            'content_publish_task_id' => (int) $task->id,
                            'workspace_id' => (int) $workspace->id,
                            'article_id' => (int) $article->id,
                            'platform_key' => $platformKey,
                            'platform_type' => $this->inferPlatformType($platformKey),
                            'publisher_account_id' => null,
                            'status' => 'pending',
                            'max_retries' => 3,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    } else {
                        foreach ($accounts->take(3) as $account) {
                            $results[] = [
                                'content_publish_task_id' => (int) $task->id,
                                'workspace_id' => (int) $workspace->id,
                                'article_id' => (int) $article->id,
                                'platform_key' => $platformKey,
                                'platform_type' => $account->platform_type,
                                'publisher_account_id' => (int) $account->id,
                                'status' => 'queued',
                                'max_retries' => 3,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }
                }
            }

            ContentPublishResult::query()->insert($results);

            return $task->fresh();
        });

        return $task;
    }

    // ── B2B 企业认证入驻（v3.0 新增） ─────────────────────

    /**
     * 创建批量 B2B 企业认证任务。
     *
     * 与 createPublishTask() 并列，共用 task/results 表。
     * 客户只需一份 EnterpriseProfile，一次认证覆盖所有 B2B 平台。
     *
     * @param  string[]  $platformKeys  B2B 平台 key 列表
     */
    public function createCertifyTask(
        Workspace $workspace,
        array $platformKeys,
        array $options = [],
        ?int $createdByAdminId = null,
        ?int $requestedByClientId = null,
    ): ContentPublishTask {
        // 校验企业档案
        $profile = \App\Models\EnterpriseProfile::query()
            ->where('workspace_id', (int) $workspace->id)
            ->first();

        if (! $profile || empty($profile->company_full_name)) {
            throw new \RuntimeException('请先完善企业档案（公司全称必填）后再进行 B2B 认证');
        }

        $platformCount = count($platformKeys);

        // 计算总作业数（每个平台最少1次认证）
        $totalJobs = 0;
        foreach ($platformKeys as $platformKey) {
            $accounts = $this->accountPool->getAvailableAccounts((int) $workspace->id, $platformKey);
            $totalJobs += max(1, $accounts->count());
        }

        $task = \Illuminate\Support\Facades\DB::transaction(function () use (
            $workspace, $platformKeys, $totalJobs, $platformCount, $options, $createdByAdminId, $requestedByClientId
        ) {
            $task = ContentPublishTask::query()->create([
                'workspace_id' => (int) $workspace->id,
                'task_name' => $options['task_name'] ?? ('B2B企业认证-'.now()->format('m-d H:i')),
                'status' => 'pending',
                'type' => 'certify',                         // ← 认证类型
                'article_ids' => null,                       // 认证任务无文章关联
                'platform_keys' => $platformKeys,
                'total_articles' => 0,                       // 认证不涉及文章
                'total_platforms' => $platformCount,
                'total_jobs' => $totalJobs,
                'use_smart_scheduling' => (bool) ($options['use_smart_scheduling'] ?? true),
                'use_content_rewrite' => false,              // 认证不需要内容改写
                'min_publish_interval_seconds' => (int) ($options['min_publish_interval_seconds'] ?? 120),
                'max_concurrent_accounts' => (int) ($options['max_concurrent_accounts'] ?? 1),
                'created_by_admin_id' => $createdByAdminId,
                'requested_by_client_user_id' => $requestedByClientId,
            ]);

            // 为每个平台创建认证明细
            $results = [];
            foreach ($platformKeys as $platformKey) {
                $platformType = $this->inferPlatformType($platformKey);
                $accounts = $this->accountPool->getAvailableAccounts((int) $workspace->id, $platformKey);

                if ($accounts->isEmpty()) {
                    $results[] = [
                        'content_publish_task_id' => (int) $task->id,
                        'workspace_id' => (int) $workspace->id,
                        'article_id' => null,                // 认证任务无文章
                        'platform_key' => $platformKey,
                        'platform_type' => $platformType,
                        'publisher_account_id' => null,
                        'status' => 'pending',               // 等待添加账号后重试
                        'max_retries' => 2,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                } else {
                    foreach ($accounts->take(1) as $account) { // 认证每平台只需1个账号
                        $results[] = [
                            'content_publish_task_id' => (int) $task->id,
                            'workspace_id' => (int) $workspace->id,
                            'article_id' => null,
                            'platform_key' => $platformKey,
                            'platform_type' => $platformType,
                            'publisher_account_id' => (int) $account->id,
                            'status' => 'queued',
                            'max_retries' => 2,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
            }

            ContentPublishResult::query()->insert($results);

            return $task->fresh();
        });

        return $task;
    }

    /**
     * 开始执行发布任务：将所有 queued 状态的明细入队。
     */
    public function dispatchPublishTask(ContentPublishTask $task): void
    {
        $task->forceFill(['status' => 'running', 'started_at' => now()])->save();

        $results = $task->results()->where('status', 'queued')->get();

        foreach ($results as $index => $result) {
            // 智能错峰：每条 Job 延迟不同秒数，避免瞬间并发
            $delaySeconds = $task->use_smart_scheduling
                ? $index * $task->min_publish_interval_seconds
                : 0;

            ProcessContentPublishJob::dispatch((int) $result->id)
                ->delay(now()->addSeconds($delaySeconds))
                ->onQueue('distribution');
        }
    }

    /**
     * 重新发布失败的任务明细。
     */
    public function retryFailed(ContentPublishTask $task): void
    {
        $failedResults = $task->results()
            ->where('status', 'failed')
            ->where('retry_count', '<', DB::raw('max_retries'))
            ->get();

        foreach ($failedResults as $result) {
            $result->forceFill(['status' => 'queued'])->save();
        }

        // 重置任务为运行状态
        $task->forceFill([
            'status' => 'running',
            'completed_at' => null,
        ])->save();

        $this->dispatchPublishTask($task);
    }

    /**
     * 取消未开始的发布任务。
     */
    public function cancelTask(ContentPublishTask $task): void
    {
        if (! in_array($task->status, ['pending', 'queued'], true)) {
            throw new \RuntimeException('只能取消未开始的任务');
        }

        $task->forceFill(['status' => 'cancelled', 'completed_at' => now()])->save();

        $task->results()
            ->whereIn('status', ['pending', 'queued'])
            ->update(['status' => 'failed', 'error_message' => '任务已取消']);
    }

    /**
     * 推断平台大类。
     */
    private function inferPlatformType(string $platformKey): string
    {
        $selfMediaPlatforms = ['toutiao', 'baijiahao', 'xiaohongshu', 'sohu', 'wangyihao', 'bilibili', 'qiehao', 'smzdm', 'douyin', 'kuaishou', 'wechat_mp'];
        if (in_array($platformKey, $selfMediaPlatforms, true)) {
            return 'self_media';
        }
        return 'b2b';
    }
}
