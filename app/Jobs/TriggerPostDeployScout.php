<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\Workspace;
use App\Services\Agent\Tools\PostDeployQuestionGenerator;
use App\Services\GeoFlow\AiVisibilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * v2.6.0 Phase 2: 发布后 Scout 检测触发器。
 *
 * 由 ContentPublishTask::updateProgress() 在发布完成时触发。
 * 拉取文章信息 → 生成探针问题 → 下发 4 轮检测（0/3/7/15 天）。
 */
class TriggerPostDeployScout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;

    /** @var list<int> 复测延迟天数 */
    private const ROUND_DELAYS = [0, 3, 7, 15];

    /** @var string 去重缓存前缀 */
    private const DEDUP_PREFIX = 'post_deploy_scout:';

    public function __construct(
        private readonly int   $workspaceId,
        private readonly array $articleIds,
    ) {}

    public function handle(
        AiVisibilityService $visibilityService,
        PostDeployQuestionGenerator $generator,
    ): void {
        $wsId = $this->workspaceId;
        $workspace = Workspace::query()->find($wsId);
        if (! $workspace) {
            Log::warning('TriggerPostDeployScout: workspace not found', ['ws_id' => $wsId]);
            return;
        }

        $brandName = $workspace->client_company_name ?: $workspace->name;
        $articles = Article::query()->whereIn('id', $this->articleIds)->get();

        if ($articles->isEmpty()) {
            Log::warning('TriggerPostDeployScout: no articles found', ['article_ids' => $this->articleIds]);
            return;
        }

        foreach ($articles as $article) {
            $keywords = (string) ($article->keywords ?? '');
            $title = (string) ($article->title ?? '');

            // 生成文章特化探针问题
            $questions = $generator->generate(
                workspaceId: $wsId,
                brandName: $brandName,
                articleTitle: $title,
                keywords: $keywords,
                industry: (string) ($workspace->config['industry'] ?? ''),
            );

            // 分发 4 轮延迟检测
            foreach (self::ROUND_DELAYS as $round => $delayDays) {
                $dedupKey = self::DEDUP_PREFIX . "{$wsId}:{$article->id}:round_{$round}";

                if (\Illuminate\Support\Facades\Cache::has($dedupKey)) {
                    continue; // 已分发过，跳过
                }

                \Illuminate\Support\Facades\Cache::put($dedupKey, true, now()->addDays(30));

                PostDeployScoutJob::dispatch(
                    workspaceId: $wsId,
                    articleId: (int) $article->id,
                    articleTitle: $title,
                    brandName: $brandName,
                    keywords: $keywords,
                    questions: $questions,
                    roundNumber: $round,
                )->delay(now()->addDays($delayDays))->onQueue('agent_scout');

                Log::info('PostDeployScout scheduled', [
                    'workspace_id' => $wsId,
                    'article_id' => $article->id,
                    'round' => $round,
                    'delay_days' => $delayDays,
                ]);
            }
        }
    }
}
