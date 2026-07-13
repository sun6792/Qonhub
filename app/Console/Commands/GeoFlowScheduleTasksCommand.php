<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\TaskRun;
use App\Services\GeoFlow\JobQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * GeoFlow 任务调度命令（对齐 bak/bin/cron.php 的入队判定）。
 *
 * 目标：
 * 1. 按任务状态与时间窗口筛选“应执行任务”；
 * 2. 为每个任务最多创建一条待执行记录（避免重复入队）；
 * 3. 入队成功后推进 next_run_at，形成周期调度。
 */
class GeoFlowScheduleTasksCommand extends Command
{
    protected $signature = 'geoflow:schedule-tasks';

    protected $description = 'Scan active GeoFlow tasks and enqueue due jobs';

    public function __construct(
        private readonly JobQueueService $jobQueueService
    ) {
        parent::__construct();
    }

    /**
     * 扫描活跃任务并按条件入队。
     */
    public function handle(): int
    {
        $now = now();
        $recoveredCount = $this->jobQueueService->recoverStaleJobs();

        $queuedCount = 0;
        $skippedCount = 0;

        $tasks = Task::query()
            ->select(['id', 'name', 'publish_interval', 'draft_limit', 'article_limit', 'created_count', 'next_run_at', 'next_publish_at', 'schedule_enabled'])
            ->where('status', 'active')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();

        $taskIds = $tasks->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        // 批量获取“已有 pending/running 执行记录”的任务集合，减少循环内 exists 查询。
        $busyTaskLookup = empty($taskIds)
            ? []
            : array_fill_keys(
                TaskRun::query()
                    ->whereIn('task_id', $taskIds)
                    ->whereIn('status', ['pending', 'running'])
                    ->groupBy('task_id')
                    ->pluck('task_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all(),
                true
            );

        $articleStats = empty($taskIds)
            ? collect()
            : \Illuminate\Support\Facades\DB::table('articles')
                ->selectRaw("
                    task_id,
                    COUNT(*) AS total_articles,
                    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_articles,
                    SUM(CASE WHEN status = 'draft' AND review_status IN ('approved','auto_approved') THEN 1 ELSE 0 END) AS publishable_drafts
                ")
                ->whereIn('task_id', $taskIds)
                ->whereNull('deleted_at')
                ->groupBy('task_id')
                ->get()
                ->mapWithKeys(static fn (object $row): array => [
                    (int) $row->task_id => [
                        'total_articles' => (int) ($row->total_articles ?? 0),
                        'draft_articles' => (int) ($row->draft_articles ?? 0),
                        'publishable_drafts' => (int) ($row->publishable_drafts ?? 0),
                    ],
                ]);

        foreach ($tasks as $task) {
            $taskId = (int) $task->id;
            if ((int) ($task->schedule_enabled ?? 1) !== 1) {
                $skippedCount++;

                continue;
            }

            $articleLimit = max(1, (int) ($task->article_limit ?? $task->draft_limit ?? 10));
            $draftLimit = max(1, (int) ($task->draft_limit ?? 10));
            $stats = $articleStats->get($taskId, ['draft_articles' => 0, 'publishable_drafts' => 0, 'total_articles' => 0]);
            // [修复] 用实际文章数代替 created_count（防直接删文导致计数不准）
            $createdCount = (int) ($stats['total_articles'] ?? (int) ($task->created_count ?? 0));
            if ((int) ($task->created_count ?? 0) !== $createdCount) {
                Task::query()->whereKey($taskId)->update(['created_count' => $createdCount, 'updated_at' => now()]);
            }
            $draftCount = (int) ($stats['draft_articles'] ?? 0);
            $publishableDrafts = (int) ($stats['publishable_drafts'] ?? 0);
            $nextPublishAt = $task->next_publish_at instanceof Carbon ? $task->next_publish_at : null;
            $canGenerate = $createdCount < $articleLimit && $draftCount < $draftLimit;
            $canPublishNow = $publishableDrafts > 0 && ($nextPublishAt === null || ! $nextPublishAt->greaterThan($now));

            if (! $canGenerate && ! $canPublishNow) {
                if ($publishableDrafts > 0 && $nextPublishAt instanceof Carbon) {
                    Task::query()->whereKey($taskId)->update([
                        'next_run_at' => $nextPublishAt,
                        'updated_at' => now(),
                    ]);
                }
                $skippedCount++;

                continue;
            }

            // 首次无 next_run_at 时仅初始化，不在当前轮直接入队（与 bak 保持一致）。
            if (! $task->next_run_at instanceof Carbon) {
                $this->jobQueueService->initializeTaskSchedule($taskId);
                $skippedCount++;

                continue;
            }

            if ($task->next_run_at->greaterThan($now) && ! $canPublishNow) {
                $skippedCount++;

                continue;
            }

            // [修复] 原逻辑每个任务只允许 1 个并行 TaskRun，导致多 Worker 无法并行
            // 改为：允许草稿池剩余空位数量的并行 TaskRun（上限 10）
            $activeCount = TaskRun::query()
                ->where('task_id', $taskId)
                ->whereIn('status', ['pending', 'running'])
                ->count();
            $slots = max(0, min(10, $draftLimit - $draftCount));
            if ($activeCount >= $slots || $slots <= 0) {
                $skippedCount++;
                continue;
            }

            $taskRunId = $this->jobQueueService->enqueueTaskJob($taskId);
            if ($taskRunId === null) {
                $skippedCount++;

                continue;
            }

            // 生成与发布解耦：调度器保持分钟级扫描，Worker 内部按 next_publish_at 控制发布。
            $nextRunAt = $now->copy()->addSeconds(60);
            Task::query()->whereKey($taskId)->update([
                'next_run_at' => $nextRunAt,
                'updated_at' => now(),
            ]);
            $queuedCount++;
        }

        // P2: 自动跑词模式
        $autoRunCount = $this->autoKeywordRun($now);

        $this->info(sprintf(
            'GeoFlow scheduler done: queued=%d, skipped=%d, recovered=%d, auto_run=%d',
            $queuedCount,
            $skippedCount,
            $recoveredCount,
            $autoRunCount
        ));

        return self::SUCCESS;
    }

    /**
     * 自动跑词模式：轮转关键词，为符合条件的 auto 任务创建 TaskRun。
     *
     * 每分钟调用一次，遵守草稿池上限和并发上限。
     */
    private function autoKeywordRun(Carbon $now): int
    {
        $autoTasks = Task::query()
            ->where('run_mode', 'auto')
            ->where('status', 'active')
            ->whereNotNull('keyword_group_id')
            ->with(['keywordGroup.keywords', 'taskRuns' => fn($q) => $q->whereIn('status', ['pending', 'running'])])
            ->get();

        $count = 0;

        foreach ($autoTasks as $task) {
            // 发布频率控制
            if ($task->last_auto_run_at && $task->last_auto_run_at->diffInSeconds($now) < (int) ($task->publish_interval ?? 60)) {
                continue;
            }

            // 草稿池上限
            $draftCount = \App\Models\Article::query()
                ->where('task_id', (int) $task->id)
                ->where('status', 'draft')
                ->count();
            $draftLimit = max(1, (int) ($task->draft_limit ?? 10));
            if ($draftCount >= $draftLimit) {
                continue;
            }

            // 并发上限
            $runningCount = $task->taskRuns->count();
            $slots = max(0, min(10, $draftLimit - $draftCount));
            if ($runningCount >= $slots || $slots <= 0) {
                continue;
            }

            // 轮转取关键词
            $keywords = $task->keywordGroup?->keywords;
            if (! $keywords || $keywords->isEmpty()) {
                continue;
            }

            $index = ((int) ($task->last_keyword_index ?? 0)) % $keywords->count();
            $keyword = $keywords->values()->get($index);
            if (! $keyword) {
                continue;
            }

            // 创建 TaskRun 并附带自动跑词元数据
            $taskRunId = $this->jobQueueService->enqueueTaskJob(
                taskId: (int) $task->id,
                jobType: 'generate_article',
                payload: [
                    'auto_run' => true,
                    'keyword' => $keyword->keyword ?? '',
                    'keyword_id' => (int) $keyword->id,
                ]
            );

            if ($taskRunId) {
                $task->forceFill([
                    'last_keyword_index' => $index + 1,
                    'last_auto_run_at' => $now,
                ])->save();
                $count++;
            }
        }

        return $count;
    }
}
