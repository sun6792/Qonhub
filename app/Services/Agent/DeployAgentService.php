<?php

namespace App\Services\Agent;

use App\Models\AgentExecution;
use App\Models\Article;
use App\Models\Workspace;
use App\Services\AI\ChatRequest;
use App\Services\AI\LlmOrchestratorService;
use App\Services\GeoFlow\Publishing\ContentPublishService;
use Illuminate\Support\Facades\Log;

/**
 * 分发 Agent — v2.6.0 Phase 2 重构。
 *
 * 核心变更：底层管线从 DistributionOrchestrator（旧版）切换为 ContentPublishService（生产级）。
 *
 * 自动继承 5 项生产级能力：
 *   ① AccountPoolService 智能选号（健康度+失败率+日配额排序）
 *   ② ContentPublishRateLimiter 平台级限流（指数退避+全局锁）
 *   ③ 失败自动重试（指数退避，最大3次，账号轮换）
 *   ④ ContentPublishTask + ContentPublishResult 逐条进度追踪
 *   ⑤ BasePlatformAdapter::syncAnchorCertification() 锚点状态自动回写
 */
class DeployAgentService
{
    public function __construct(
        private readonly ContentPublishService $publishService,
        private readonly RpaRoutingDecider $routingDecider,
    ) {}

    /**
     * B 型执行入口 — AgentDispatcherService 状态机调用。
     *
     * @return array{ task_id: int, total_jobs: int, platform_count: int, route_summary: array, deployed_at: string }
     */
    public function execute(AgentExecution $execution): array
    {
        $wsId = (int) $execution->workspace_id;
        $workspace = Workspace::query()->find($wsId);
        if (! $workspace) {
            throw new \RuntimeException("工作空间不存在: {$wsId}");
        }

        $strategyOutput = $execution->strategy_output ?? [];
        $contentOutput = $execution->content_output ?? [];
        $articleId = $contentOutput['article_id'] ?? null;
        // 兜底：从 workspace 查最近一篇已发布文章
        if (!$articleId) {
            $latestArticle = \App\Models\Article::whereIn('id', function ($sub) use ($wsId) {
                $sub->select('assignable_id')->from('workspace_assignments')
                    ->where('assignable_type', \App\Models\Article::class)
                    ->where('workspace_id', $wsId);
            })->where('status', 'published')->latest()->first();
            if ($latestArticle) $articleId = (int) $latestArticle->id;
        }
        $platforms = $strategyOutput['task_config']['target_platforms'] ?? ['toutiao', 'baijiahao'];

        // ── v2.6.0: 统一走 ContentPublishService 生产级管线 ──
        // 有文章 → 创建 ContentPublishTask → dispatch 到 distribution 队列
        $taskId = null;
        $totalJobs = 0;
        $routeSummary = [];

        if ($articleId && $platforms !== []) {
            $article = Article::query()->find($articleId);
            if ($article) {
                try {
                    // ① 创建 ContentPublishTask（自动分解为 ContentPublishResult 逐条记录）
                    $task = $this->publishService->createPublishTask(
                        workspace: $workspace,
                        articleIds: [$articleId],
                        platformKeys: $platforms,
                        options: [
                            'task_name' => '智能体自动分发 #' . $execution->id . ' - ' . now()->format('m-d H:i'),
                            'use_smart_scheduling' => true,
                            'use_content_rewrite' => true,
                            'rewrite_mode' => 'per_platform',
                        ],
                    );

                    // ② dispatch → distribution 队列（自动继承 AccountPool / RateLimiter / Retry / Progress / Anchor sync）
                    $this->publishService->dispatchPublishTask($task);

                    $taskId = (int) $task->id;
                    $totalJobs = (int) $task->total_jobs;

                    // 路由摘要
                    foreach ($platforms as $platform) {
                        $route = $this->routingDecider->decide($platform, $wsId);
                        $routeSummary[] = ['platform' => $platform, 'route' => $route];
                    }

                    Log::info('DeployAgent: ContentPublishTask created', [
                        'execution_id' => $execution->id,
                        'task_id' => $taskId,
                        'total_jobs' => $totalJobs,
                        'platforms' => $platforms,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('DeployAgent: createPublishTask failed', [
                        'execution_id' => $execution->id,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        }

        // ── 锚点状态：由 ProcessContentPublishJob 执行时自动回写 ──
        // BasePlatformAdapter::syncAnchorCertification() + cascadeCertifySuccess()
        // 在 publish/register 成功后自动调用，无需 DeployAgent 重复实现。

        return [
            'task_id' => $taskId,
            'total_jobs' => $totalJobs,
            'platform_count' => count($platforms),
            'route_summary' => $routeSummary,
            'deployed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * [A型增强] LLM 自主分析 RPA 发布失败原因，决策重试/换账号/降级。
     * 仅在 B 型发布失败且 A 型增强开启时调用，不修改 B 型 execute() 流程。
     */
    public function executeATypeErrorAnalysis(int $workspaceId, string $platformKey, string $errorMessage): array
    {
        try {
            $response = app(LlmOrchestratorService::class)->chat(new ChatRequest(
                providerCode: 'deepseek',
                modelId: 'deepseek-chat',
                messages: [
                    ['role' => 'system', 'content' => '你是RPA自动化运维专家。分析发布失败的错误信息，判断失败类型（CAPTCHA/认证过期/内容拒绝/限流/未知），输出推荐处理方案。'],
                    ['role' => 'user', 'content' => "平台：{$platformKey}\n错误信息：{$errorMessage}\n\n请判断：\n1. 失败类型分类（captcha/auth_expired/content_rejected/rate_limited/unknown）\n2. 推荐处理方案（retry/switch_account/fallback_playwright/manual_review）\n3. 是否需要人工介入"],
                ],
                options: ['max_tokens' => 256],
                workspaceId: $workspaceId,
            ));

            return [
                'success' => true,
                'analysis' => $response->text,
                'platform' => $platformKey,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'platform' => $platformKey];
        }
    }
}
