<?php

namespace App\Console\Commands;

use App\Models\PublishingSchedule;
use App\Services\GeoFlow\Publishing\RpaEngineClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PublishScheduledArticles extends Command
{
    protected $signature = 'publish:scheduled';
    protected $description = '扫描 publishing_schedules 表，发布到时间的文章';

    public function handle(): int
    {
        $schedules = PublishingSchedule::query()
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->with(['article', 'workspace'])
            ->limit(10)
            ->get();

        if ($schedules->isEmpty()) {
            return 0;
        }

        $rpaClient = app(RpaEngineClient::class);

        // 先检查 RPA 引擎健康状态
        $health = $rpaClient->healthCheck();
        if (! ($health['healthy'] ?? false)) {
            Log::warning('publish:scheduled — RPA engine unreachable, skipping');
            return 1;
        }

        $completed = 0;
        $failed = 0;

        foreach ($schedules as $schedule) {
            $schedule->status = 'processing';
            $schedule->save();

            $article = $schedule->article;
            if (! $article) {
                $schedule->status = 'failed';
                $schedule->error_message = '文章不存在或已删除';
                $schedule->save();
                $failed++;
                continue;
            }

            // 文章封面图：优先用文章的 cover_image，否则不传（让 RPA 脚本用自己的默认图）
            $coverImage = null;
            if (! empty($article->cover_image)) {
                $coverImage = $article->cover_image;
            }

            try {
                $options = [
                    'workspace_id' => (int) $schedule->workspace_id,
                    'timeout_seconds' => 120,
                ];
                if ($coverImage !== null) {
                    $options['cover_image'] = $coverImage;
                }

                $result = $rpaClient->executeTask([
                    'platform' => $schedule->platform,
                    'action' => 'publish_article',
                    'account' => [],
                    'enterprise' => ['workspace_id' => (int) $schedule->workspace_id],
                    'content' => [
                        'title' => (string) $article->title,
                        'content' => (string) $article->content,
                        'article_id' => (int) $article->id,
                    ],
                    'options' => $options,
                ]);

                if ($result['success'] ?? false) {
                    $schedule->status = 'completed';
                    $schedule->published_at = now();
                    $completed++;
                    Log::info("publish:scheduled — article {$article->id} to {$schedule->platform} OK");
                } else {
                    $schedule->status = 'failed';
                    $schedule->error_message = $result['error'] ?? 'RPA 执行失败';
                    $failed++;
                    Log::warning("publish:scheduled — article {$article->id} failed: {$schedule->error_message}");
                }
            } catch (\Throwable $e) {
                $schedule->status = 'failed';
                $schedule->error_message = $e->getMessage();
                $failed++;
                Log::error("publish:scheduled — exception: {$e->getMessage()}");
            }

            $schedule->save();
        }

        $this->info("Done: {$completed} succeeded, {$failed} failed");
        return $completed > 0 ? 0 : ($failed > 0 ? 1 : 0);
    }
}
