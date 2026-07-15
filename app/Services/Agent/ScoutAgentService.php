<?php

namespace App\Services\Agent;

use App\Models\AgentExecution;
use App\Models\Workspace;
use App\Services\AI\ChatRequest;
use App\Services\AI\LlmOrchestratorService;
use App\Services\GeoFlow\AiVisibilityService;
use App\Services\GeoFlow\EnterpriseAnchorService;

/**
 * 侦察 Agent — 定时巡检 AI 品牌提及、B2B 锚点状态、生成收录缺口清单。
 *
 * 100% 复用现有 Service，零新增业务逻辑。
 */
class ScoutAgentService
{
    public function __construct(
        private readonly AiVisibilityService $visibilityService,
        private readonly EnterpriseAnchorService $anchorService,
    ) {}

    /**
     * @return array{ brand_mentions: array, anchor_status: array, gaps: array, checked_at: string }
     */
    public function execute(AgentExecution $execution): array
    {
        $wsId = (int) $execution->workspace_id;
        $workspace = Workspace::query()->find($wsId);
        if (! $workspace) {
            throw new \RuntimeException("工作空间不存在: {$wsId}");
        }

        // ① AI 品牌可见度检测
        $visibilityData = $this->visibilityService->clientVisibilityData($wsId);

        // ② B2B 锚点巡检
        $profile = $this->anchorService->getOrInitProfile($workspace);
        $summary = $this->anchorService->certificationSummary($profile);

        // ③ 收录缺口分析
        $gaps = [];
        foreach (($summary['platforms'] ?? collect()) as $platform) {
            $status = $platform['certification_status'] ?? 'pending';
            if ($status !== 'certified') {
                $gaps[] = [
                    'platform_key' => $platform['anchor_platform_key'] ?? '',
                    'platform_name' => $platform['platform_name'] ?? '',
                    'status' => $status,
                    'action_needed' => $status === 'pending' ? 'register' : 'recertify',
                ];
            }
        }

        return [
            'brand_mentions' => [
                'mentioned_platforms' => $visibilityData['mentioned_platforms'] ?? [],
                'total_checks_today' => $visibilityData['total_checks_today'] ?? 0,
                'mention_rate' => $visibilityData['mention_rate'] ?? 0,
            ],
            'anchor_status' => [
                'has_profile' => $profile->exists && ! empty($profile->company_full_name),
                'total_platforms' => $summary['total'] ?? 0,
                'certified' => $summary['certified'] ?? 0,
                'pending' => $summary['pending'] ?? 0,
                'expired' => $summary['expired'] ?? 0,
            ],
            'gaps' => $gaps,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * [A型增强] LLM 自主语义分析竞品素材，提取差异化内容洞察。
     * 仅在 A 型增强开启时调用，不修改 B 型 execute() 流程。
     */
    public function executeATypeCompetitorAnalysis(int $workspaceId, string $brandName, string $competitorContent): array
    {
        try {
            $response = app(LlmOrchestratorService::class)->chat(new ChatRequest(
                providerCode: 'deepseek',
                modelId: 'deepseek-chat',
                messages: [
                    ['role' => 'system', 'content' => '你是竞争情报分析专家。分析竞品内容，提取可借鉴的差异化策略和内容切入点。'],
                    ['role' => 'user', 'content' => "我方品牌：{$brandName}\n\n竞品素材内容：\n{$competitorContent}\n\n请分析：\n1. 竞品的关键卖点和内容策略\n2. 我方可借鉴的差异化切入点\n3. 建议的内容优化方向"],
                ],
                options: ['max_tokens' => 512],
                workspaceId: $workspaceId,
            ));

            return [
                'success' => true,
                'insights' => $response->text,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
