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

        // ① 关键词规划：初始入参 + Scout 发现的品牌提及词合并去重
        $keywords = array_unique(array_merge(
            $inputData['keywords'] ?? [],
            $this->extractKeywordsFromScout($scoutOutput)
        ));
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

    /**
     * 从 Scout 的 live_snapshots 中提取品牌相关关键词。
     * 策略：取被提及平台的回答预览中出现的高频中文词组（2-4字）。
     * 跳过 AI 负面/拒绝回答（"很抱歉/不了解/没有记录"等），避免垃圾词污染。
     */
    private function extractKeywordsFromScout(array $scoutOutput): array
    {
        $snapshots = $scoutOutput['live_snapshots'] ?? [];
        if (empty($snapshots)) return [];

        // 负面回答模式：AI 明确表示不了解/无法回答
        $negativePatterns = [
            '很抱歉', '并不了解', '不太清楚', '没有相关', '无法提供',
            '暂未收录', '不知道', '没有记录', '没有找到', '未找到',
            'no information', "I don't know", 'cannot provide',
            '无法回答', '暂无数据', '没有相关记录',
        ];

        $texts = [];
        foreach ($snapshots as $s) {
            if (! empty($s['mentioned']) && ! empty($s['preview'])) {
                // 过滤掉 AI 拒绝回答的内容（stripos 误判为"提及"）
                $preview = $s['preview'];
                foreach ($negativePatterns as $neg) {
                    if (mb_stripos($preview, $neg) !== false) {
                        continue 2; // 跳过这条快照
                    }
                }
                $texts[] = $preview;
            }
        }
        if (empty($texts)) return [];

        $combined = implode(' ', $texts);
        // 提取 2-4 字中文词组
        preg_match_all('/[\x{4e00}-\x{9fa5}]{2,4}/u', $combined, $matches);
        $words = $matches[0] ?? [];

        // 过滤停用词 + 按频率排序取 top 10
        $stopWords = ['请问', '是否', '知道', '这个', '如果', '什么', '可以', '一个', '不过', '但是', '因为', '所以', '而且', '然后', '就是', '或者', '详细', '描述', '品牌', '产品', '回答', '如实', '以下', '并不了', '或产品', '在我的知'];
        $words = array_diff($words, $stopWords);
        $freq = array_count_values($words);
        arsort($freq);

        return array_slice(array_keys($freq), 0, 10);
    }
}
