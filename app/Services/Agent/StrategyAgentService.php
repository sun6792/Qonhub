<?php

namespace App\Services\Agent;

use App\Models\AgentExecution;
use App\Models\Task;
use App\Services\GeoFlow\GeoContentScorer;
use App\Services\GeoFlow\WorkspaceService;

/**
 * 策略 Agent — 基于侦察数据生成关键词规划、渠道排期、任务配置。
 *
 * 复用 GeoContentScorer、WorkspaceService 和现有 Task 模型。
 */
class StrategyAgentService
{
    public function __construct(
        private readonly GeoContentScorer $scorer,
        private readonly WorkspaceService $workspaceService,
    ) {}

    /**
     * @return array{ keywords: array, channel_plan: array, task_config: array, estimated_geo_score: int }
     */
    public function execute(AgentExecution $execution): array
    {
        $wsId = (int) $execution->workspace_id;
        $scoutOutput = $execution->scout_output ?? [];
        $inputData = $execution->input_data ?? [];

        // ① 关键词规划：从输入数据中提取关键词
        $keywords = $inputData['keywords'] ?? [];
        $brandName = $inputData['brand_name'] ?? '';

        // ② 渠道排期：基于锚点状态决定优先补齐哪些平台
        $gaps = $scoutOutput['gaps'] ?? [];
        $channelPlan = [];
        foreach ($gaps as $gap) {
            $channelPlan[] = [
                'platform_key' => $gap['platform_key'],
                'platform_name' => $gap['platform_name'],
                'action' => $gap['action_needed'],
                'priority' => $gap['action_needed'] === 'register' ? 'high' : 'medium',
            ];
        }

        // ③ 任务配置：生成可执行的任务参数
        $taskConfig = [
            'keywords' => $keywords,
            'target_platforms' => $inputData['platforms'] ?? ['toutiao', 'baijiahao'],
            'brand_name' => $brandName,
            'content_count' => $inputData['content_count'] ?? 3,
            'geo_threshold' => 70,
        ];

        // ④ 预估 GEO 评分（基于品牌名 + 关键词空跑评分器）
        $sampleTitle = $brandName ? "{$brandName}在" . implode('、', array_slice($keywords, 0, 2)) . "方面的优势" : '';
        $estimatedScore = $sampleTitle
            ? $this->scorer->quickScore($sampleTitle, '')
            : 50;

        return [
            'keywords' => $keywords,
            'channel_plan' => $channelPlan,
            'task_config' => $taskConfig,
            'estimated_geo_score' => $estimatedScore,
            'planned_at' => now()->toIso8601String(),
        ];
    }
}
