<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ContentPublishResult;
use App\Models\ContentPublishTask;
use App\Models\ContentPublisherAccount;
use App\Services\GeoFlow\Publishing\AccountPoolService;
use App\Services\GeoFlow\Publishing\ContentPublishRateLimiter;
use App\Services\GeoFlow\Publishing\PlatformAdapterFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 单次内容发布 Job。
 *
 * 一篇文章 × 一个平台 = 一个 Job。放入 distribution 队列，
 * 复用现有 DistributionRetryPolicy 的重试逻辑。
 */
class ProcessContentPublishJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $publishResultId,
    ) {}

    public function tags(): array
    {
        $result = ContentPublishResult::query()->whereKey($this->publishResultId)->first();

        return array_values(array_filter([
            'publish',
            'task:'.($result?->content_publish_task_id ?? 0),
            'platform:'.($result?->platform_key ?? ''),
        ]));
    }

    public function handle(
        AccountPoolService $accountPool,
        ContentPublishRateLimiter $rateLimiter,
    ): void {
        /** @var ContentPublishResult|null $result */
        $result = ContentPublishResult::query()->whereKey($this->publishResultId)->first();
        if (! $result || $result->status === 'success') {
            return;
        }

        // 找文章
        $article = Article::query()->find((int) $result->article_id);
        if (! $article) {
            $result->forceFill(['status' => 'failed', 'error_message' => '文章不存在'])->save();

            return;
        }

        // 找账号：优先用已有账号，否则自动选择
        $account = null;
        if ($result->publisher_account_id) {
            $account = ContentPublisherAccount::query()->find((int) $result->publisher_account_id);
        }
        if (! $account || ! $account->isAvailable()) {
            $account = $accountPool->selectBestAccount(
                (int) $result->workspace_id,
                $result->platform_key
            );
        }
        if (! $account) {
            $result->forceFill(['status' => 'failed', 'error_message' => '无可用账号'])->save();

            return;
        }

        // 频率控制：等待账号可用
        if (! $rateLimiter->canPublishNow($account)) {
            // 延迟重试
            $delay = $rateLimiter->waitSeconds($account);
            static::dispatch($this->publishResultId)
                ->delay(now()->addSeconds(max(10, $delay)))
                ->onQueue('distribution');

            return;
        }

        // 获取全局锁
        if (! $rateLimiter->acquireGlobalLock((int) $result->workspace_id, $result->platform_key)) {
            static::dispatch($this->publishResultId)
                ->delay(now()->addSeconds(5))
                ->onQueue('distribution');

            return;
        }

        try {
            $result->forceFill([
                'status' => 'sending',
                'publisher_account_id' => (int) $account->id,
            ])->save();

            // 创建适配器并根据任务类型执行不同操作
            $adapter = PlatformAdapterFactory::create($account);

            // 判断任务类型：certify = B2B认证入驻，publish = 内容发布
            $task = ContentPublishTask::query()->find((int) $result->content_publish_task_id);
            if ($task && $task->type === 'certify') {
                // B2B 企业认证入驻
                $adapter->register($result);
            } else {
                // 内容发布（默认，兼容历史数据）
                $adapter->publish($article, $result);
            }

        } catch (Throwable $e) {
            // 失败处理
            $result->forceFill([
                'status' => 'failed',
                'error_code' => 'exception',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'retry_count' => $result->retry_count + 1,
                'completed_at' => now(),
            ])->save();

            // 自动轮换账号
            $newAccount = $accountPool->rotateIfNeeded($account);
            if ($newAccount && $result->canRetry()) {
                $result->forceFill(['publisher_account_id' => (int) $newAccount->id])->save();
                static::dispatch($this->publishResultId)
                    ->delay(now()->addSeconds(30))
                    ->onQueue('distribution');
            }
        } finally {
            $rateLimiter->releaseGlobalLock((int) $result->workspace_id, $result->platform_key);

            // 更新父任务进度
            try {
                $task = ContentPublishTask::query()->find((int) $result->content_publish_task_id);
                $task?->updateProgress();
            } catch (Throwable) {
                // 进度更新失败不影响主流程
            }
        }
    }

    /**
     * Job 层面的失败回调。
     */
    public function failed(?Throwable $exception = null): void
    {
        $result = ContentPublishResult::query()->whereKey($this->publishResultId)->first();
        if (! $result) {
            return;
        }

        if ($result->canRetry()) {
            $result->increment('retry_count');
            static::dispatch($this->publishResultId)
                ->delay(now()->addSeconds(60 * pow(2, $result->retry_count)))
                ->onQueue('distribution');
        } else {
            $result->forceFill([
                'status' => 'failed',
                'error_message' => '队列中断: '.($exception?->getMessage() ?? ''),
                'completed_at' => now(),
            ])->save();
        }
    }
}
