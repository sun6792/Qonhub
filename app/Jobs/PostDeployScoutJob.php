<?php

namespace App\Jobs;

use App\Models\AiVisibilityCheck;
use App\Services\GeoFlow\AiVisibilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * v2.6.0 Phase 3: 延迟复测 Job。
 *
 * 由 TriggerPostDeployScout 延迟分发（0/3/7/15 天）。
 * 使用文章特化探针问题逐个平台执行检测，完全复用 PlatformScoutJob 的下层逻辑。
 */
class PostDeployScoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;

    /** @var list<string> API 平台（直接调用 LlmOrchestrator） */
    private const API_PLATFORMS = ['deepseek', 'qianwen', 'kimi', 'wenxin', 'zhipu'];

    /** @var list<string> 浏览器平台（Playwright MCP） */
    private const BROWSER_PLATFORMS = ['doubao', 'yuanbao', 'baidu_ai', 'xf_xinghuo', 'nami_ai', 'wechat_ai', 'douyin_ai', 'quark_ai'];

    public function __construct(
        private readonly int    $workspaceId,
        private readonly int    $articleId,
        private readonly string $articleTitle,
        private readonly string $brandName,
        private readonly string $keywords,
        private readonly array  $questions,
        private readonly int    $roundNumber,
    ) {}

    public function handle(): void
    {
        $allPlatforms = array_merge(self::API_PLATFORMS, self::BROWSER_PLATFORMS);
        $dispatched = 0;

        foreach ($this->questions as $question) {
            $queryText = $question['query'];
            $queryType = $question['type'];

            foreach ($allPlatforms as $platform) {
                PlatformScoutJob::dispatch(
                    $this->workspaceId,
                    $platform,
                    $this->keywords ?: ($this->articleTitle),
                    $this->brandName,
                )->onQueue('agent_scout');

                $dispatched++;
            }
        }

        Log::info('PostDeployScout: round dispatched', [
            'workspace_id' => $this->workspaceId,
            'article_id' => $this->articleId,
            'round' => $this->roundNumber,
            'questions' => count($this->questions),
            'platforms' => count($allPlatforms),
            'total_jobs' => $dispatched,
        ]);
    }
}
