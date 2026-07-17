<?php

namespace App\Services\Agent;

use App\Models\AgentExecution;
use App\Models\AgentStepMemory;
use Illuminate\Support\Facades\Log;

/**
 * 双层记忆服务 — v2.8.0。
 *
 * Step-level Memory: 每次管道执行后记录各 Agent 输入/输出/指标。
 * Creator-level Memory: 跨任务检索可复用的成功模式。
 *
 * 参考：MAGEO Hierarchical Memory (ACL 2026)
 */
class MemoryService
{
    /**
     * 管道执行完成后，记录所有 Agent 输出到记忆库。
     */
    public function recordExecution(AgentExecution $execution): void
    {
        $wsId = (int) $execution->workspace_id;
        $execId = (int) $execution->id;

        $allOutputs = [
            'scout'    => $execution->scout_output ?? [],
            'strategy' => $execution->strategy_output ?? [],
            'content'  => $execution->content_output ?? [],
            'deploy'   => $execution->deploy_output ?? [],
            'review'   => $execution->review_output ?? [],
        ];

        // 提取跨任务标签
        $tags = $this->extractTags($allOutputs);

        foreach (['scout', 'strategy', 'content', 'deploy', 'review'] as $agentType) {
            $output = $allOutputs[$agentType] ?? [];
            if (empty($output)) continue;

            $metrics = $this->extractMetrics($agentType, $output, $allOutputs);

            AgentStepMemory::create([
                'workspace_id' => $wsId,
                'execution_id' => $execId,
                'agent_type' => $agentType,
                'input_digest' => $this->digestInput($agentType, $allOutputs),
                'output_digest' => $this->digestOutput($agentType, $output),
                'success' => $execution->isCompleted(),
                'metrics' => $metrics,
                'tags' => $tags,
                'pattern_key' => $this->computePatternKey($wsId, $tags),
            ]);
        }

        Log::info('MemoryService: execution recorded', [
            'execution_id' => $execId,
            'workspace_id' => $wsId,
            'tags' => $tags,
        ]);
    }

    /**
     * 检索相关记忆 — 供 Strategy Agent 在生成策略前参考。
     *
     * @return list<array{agent_type:string, output_digest:string, metrics:array, created_at:string}>
     */
    public function retrieveRelevant(int $workspaceId, array $keywords, array $platforms, int $limit = 5): array
    {
        $query = AgentStepMemory::query()
            ->where('workspace_id', $workspaceId)
            ->where('success', true)
            ->whereIn('agent_type', ['strategy', 'content', 'review'])
            ->orderByDesc('created_at')
            ->limit($limit);

        $memories = $query->get();

        return $memories->map(fn ($m) => [
            'agent_type' => $m->agent_type,
            'output_digest' => $m->output_digest,
            'metrics' => $m->metrics,
            'tags' => $m->tags,
            'created_at' => $m->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * 提取跨任务模式 — 聚合最近 N 次成功执行的共性。
     *
     * @return array{ best_platforms: array, avg_geo_score: float, top_keywords: array, success_rate: float }
     */
    public function aggregatePatterns(int $workspaceId, int $recentLimit = 10): array
    {
        $memories = AgentStepMemory::query()
            ->where('workspace_id', $workspaceId)
            ->where('success', true)
            ->where('agent_type', 'review')
            ->orderByDesc('created_at')
            ->limit($recentLimit)
            ->get();

        if ($memories->isEmpty()) {
            return [
                'best_platforms' => [],
                'avg_geo_score' => 0,
                'top_keywords' => [],
                'success_rate' => 0,
                'total_memories' => 0,
            ];
        }

        $total = $memories->count();
        $scores = [];
        $platformCounts = [];
        $allKeywords = [];

        foreach ($memories as $m) {
            $metrics = $m->metrics ?? [];
            $scores[] = (float) ($metrics['geo_score'] ?? 0);
            foreach ($metrics['effective_platforms'] ?? [] as $p) {
                $platformCounts[$p] = ($platformCounts[$p] ?? 0) + 1;
            }
            foreach ($m->tags['keywords'] ?? [] as $kw) {
                $allKeywords[] = $kw;
            }
        }

        arsort($platformCounts);
        $keywordFreq = array_count_values($allKeywords);
        arsort($keywordFreq);

        return [
            'best_platforms' => array_slice(array_keys($platformCounts), 0, 5),
            'avg_geo_score' => round(array_sum($scores) / max(count($scores), 1), 1),
            'top_keywords' => array_slice(array_keys($keywordFreq), 0, 10),
            'success_rate' => round($total / max($recentLimit, 1) * 100),
            'total_memories' => $total,
        ];
    }

    // ══════════════════════════════════════════════════════════
    //  辅助方法
    // ══════════════════════════════════════════════════════════

    private function extractTags(array $allOutputs): array
    {
        $scout = $allOutputs['scout'] ?? [];
        $strategy = $allOutputs['strategy'] ?? [];
        $deploy = $allOutputs['deploy'] ?? [];

        return [
            'keywords' => $strategy['task_config']['keywords'] ?? [],
            'platforms' => $deploy['published_channels'] ?? [],
            'industry' => $scout['brand_mentions']['mentioned_platforms'] ?? [],
        ];
    }

    private function extractMetrics(string $agentType, array $output, array $allOutputs): array
    {
        return match ($agentType) {
            'scout' => [
                'mention_rate' => ($allOutputs['scout']['brand_mentions']['mention_rate'] ?? 0) * 100,
                'mentioned_platforms' => $allOutputs['scout']['brand_mentions']['mentioned_platforms'] ?? [],
                'certified_count' => $allOutputs['scout']['anchor_status']['certified'] ?? 0,
            ],
            'strategy' => [
                'estimated_geo_score' => $output['estimated_geo_score'] ?? 0,
                'keyword_count' => count($output['keywords'] ?? []),
                'platform_count' => count($output['task_config']['target_platforms'] ?? []),
            ],
            'content' => [
                'geo_score' => $output['geo_score'] ?? 0,
                'geo_grade' => $output['geo_grade'] ?? 'F',
                'content_length' => $output['content_length'] ?? 0,
            ],
            'deploy' => [
                'total_jobs' => $output['total_jobs'] ?? 0,
                'published_count' => count($output['published_channels'] ?? []),
                'failed_count' => count($output['failed_channels'] ?? []),
            ],
            'review' => [
                'geo_score' => $allOutputs['content']['geo_score'] ?? 0,
                'effective_platforms' => $allOutputs['review']['scout_brief']['effective_platforms'] ?? [],
                'mention_rate' => $allOutputs['review']['scout_brief']['mention_rate'] ?? 0,
                'channels_published' => $allOutputs['review']['summary']['channels_published'] ?? 0,
            ],
            default => [],
        };
    }

    private function digestInput(string $agentType, array $allOutputs): string
    {
        return match ($agentType) {
            'scout' => '品牌词 + B2B锚点巡检',
            'strategy' => '关键词：' . implode(',', array_slice($allOutputs['scout']['live_snapshots'] ?? [], 0, 3) ?: []),
            'content' => '策略输出：' . json_encode($allOutputs['strategy']['task_config'] ?? [], JSON_UNESCAPED_UNICODE),
            'deploy' => '文章ID：' . ($allOutputs['content']['article_id'] ?? 'N/A'),
            'review' => '全链路汇总',
            default => '',
        };
    }

    private function digestOutput(string $agentType, array $output): string
    {
        return match ($agentType) {
            'scout' => '提及平台：' . count($output['live_snapshots'] ?? []) . '个',
            'strategy' => '策略模式：' . ($output['strategy_mode'] ?? 'rule'),
            'content' => 'GEO：' . ($output['geo_score'] ?? 0) . '/' . ($output['geo_grade'] ?? 'F'),
            'deploy' => '分发：' . ($output['total_jobs'] ?? 0) . '条作业',
            'review' => '需迭代：' . (($output['needs_iteration'] ?? false) ? '是' : '否'),
            default => '',
        };
    }

    private function computePatternKey(int $workspaceId, array $tags): string
    {
        $seeds = [
            (string) $workspaceId,
            implode(',', array_slice($tags['keywords'] ?? [], 0, 5)),
            implode(',', $tags['platforms'] ?? []),
        ];
        return 'pat_' . substr(md5(implode('|', $seeds)), 0, 12);
    }
}
