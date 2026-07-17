<?php

namespace App\Services\Agent;

use App\Models\AgentExecution;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;

/**
 * 五智能体顶层调度器 — B 型状态机编排。
 *
 * 固定流程: Scout → Strategy → Content → Deploy → Review
 * Content 可自循环 (GEO < 70 重写)
 * Deploy 可自循环 (多平台分发)
 */
class AgentDispatcherService
{
    public function __construct(
        private readonly ScoutAgentService   $scoutAgent,
        private readonly StrategyAgentService $strategyAgent,
        private readonly ContentAgentService  $contentAgent,
        private readonly DeployAgentService   $deployAgent,
        private readonly ReviewAgentService   $reviewAgent,
    ) {}

    /**
     * 启动一个五智能体工作流。
     *
     * @param  int    $workspaceId  工作空间ID
     * @param  array  $inputData    初始输入（任务ID、关键词、触发参数等）
     * @param  string $triggerType  触发方式 (manual/schedule/webhook)
     * @param  int|null $adminId    触发人ID
     */
    public function start(int $workspaceId, array $inputData = [], string $triggerType = 'manual', ?int $adminId = null): AgentExecution
    {
        // 五智能体全链路最长 600 秒，防止管道阻塞
        set_time_limit(600);

        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            throw new \InvalidArgumentException("工作空间不存在: {$workspaceId}");
        }

        $execution = AgentExecution::query()->create([
            'workspace_id' => $workspaceId,
            'workflow_key' => 'scout_strategy_content_deploy_review',
            'current_state' => AgentExecution::STATE_IDLE,
            'input_data' => $inputData,
            'trigger_type' => $triggerType,
            'triggered_by_admin_id' => $adminId,
            'started_at' => now(),
        ]);

        Log::info('Agent workflow started', [
            'execution_id' => $execution->id,
            'workspace_id' => $workspaceId,
            'trigger_type' => $triggerType,
        ]);

        // 立即推进到侦察阶段
        $this->executeScout($execution);

        return $execution;
    }

    /**
     * 从指定状态恢复执行（断点续跑 / 人工干预）。
     * 失败状态自动回退到最近的成功阶段重试。
     */
    public function resume(int $executionId): AgentExecution
    {
        $execution = AgentExecution::query()->findOrFail($executionId);

        Log::info('Agent workflow resumed', [
            'execution_id' => $executionId,
            'current_state' => $execution->current_state,
        ]);

        // 失败状态：回退到最近的成功阶段
        if ($execution->isFailed()) {
            $execution->retry_count = 0;
            $execution->error_data = null;
            if (! empty($execution->review_output)) {
                $execution->transitionTo(AgentExecution::STATE_REVIEWING);
            } elseif (! empty($execution->deploy_output)) {
                $execution->transitionTo(AgentExecution::STATE_DEPLOYING);
            } elseif (! empty($execution->content_output)) {
                $execution->transitionTo(AgentExecution::STATE_WRITING);
            } elseif (! empty($execution->strategy_output)) {
                $execution->transitionTo(AgentExecution::STATE_PLANNING);
            } elseif (! empty($execution->scout_output)) {
                $execution->transitionTo(AgentExecution::STATE_SCOUTING);
            } else {
                $execution->transitionTo(AgentExecution::STATE_IDLE);
            }
        }

        $this->advanceFrom($execution);

        return $execution;
    }

    /**
     * 根据当前状态推进到下一个 Agent。
     */
    private function advanceFrom(AgentExecution $execution): void
    {
        switch ($execution->current_state) {
            case AgentExecution::STATE_SCOUTING:
            case AgentExecution::STATE_IDLE:
                $this->executeScout($execution);
                break;
            case AgentExecution::STATE_PLANNING:
                $this->executeStrategy($execution);
                break;
            case AgentExecution::STATE_WRITING:
                $this->executeContent($execution);
                break;
            case AgentExecution::STATE_DEPLOYING:
                $this->executeDeploy($execution);
                break;
            case AgentExecution::STATE_REVIEWING:
                $this->executeReview($execution);
                break;
            default:
                break;
        }
    }

    // ── 各 Agent 执行方法 ──────────────────────────────

    private function executeScout(AgentExecution $execution): void
    {
        $execution->current_agent = AgentExecution::AGENT_SCOUT;
        $execution->transitionTo(AgentExecution::STATE_SCOUTING);
        $execution->save();

        try {
            $output = $this->scoutAgent->execute($execution);
            $execution->saveAgentOutput(AgentExecution::AGENT_SCOUT, $output, AgentExecution::STATE_PLANNING);
            $this->executeStrategy($execution);
        } catch (\Throwable $e) {
            Log::error('ScoutAgent failed', ['execution_id' => $execution->id, 'error' => $e->getMessage()]);
            $this->handleAgentFailure($execution, AgentExecution::AGENT_SCOUT, $e);
        }
    }

    private function executeStrategy(AgentExecution $execution): void
    {
        $execution->current_agent = AgentExecution::AGENT_STRATEGY;
        $execution->save();

        try {
            $output = $this->strategyAgent->execute($execution);
            $execution->saveAgentOutput(AgentExecution::AGENT_STRATEGY, $output, AgentExecution::STATE_WRITING);
            $this->executeContent($execution);
        } catch (\Throwable $e) {
            Log::error('StrategyAgent failed', ['execution_id' => $execution->id, 'error' => $e->getMessage()]);
            $this->handleAgentFailure($execution, AgentExecution::AGENT_STRATEGY, $e);
        }
    }

    private function executeContent(AgentExecution $execution, int $qualityRetries = 0): void
    {
        $execution->current_agent = AgentExecution::AGENT_CONTENT;
        $execution->save();

        try {
            $output = $this->contentAgent->execute($execution);

            // AI 调用本身失败（非质量问题）→ 跳过自循环重试，避免浪费配额
            $generationError = $output['generation_error'] ?? null;

            // GEO < 70 且非 AI 故障 → 自循环重写（质量重试，本地计数器，不影响 failure retry）
            if (($output['geo_score'] ?? 0) < 70 && $generationError === null && $qualityRetries < 3) {
                $qualityRetries++;
                $execution->content_output = $output;
                $execution->save();
                Log::info('ContentAgent: GEO<70, quality retry', [
                    'execution_id' => $execution->id,
                    'geo_score' => $output['geo_score'],
                    'quality_retry' => $qualityRetries,
                ]);
                $this->executeContent($execution, $qualityRetries);
                return;
            }

            if ($generationError !== null) {
                Log::warning('ContentAgent: AI call failed, skipping quality retries', [
                    'execution_id' => $execution->id,
                    'error' => $generationError,
                ]);
            }

            $execution->saveAgentOutput(AgentExecution::AGENT_CONTENT, $output, AgentExecution::STATE_DEPLOYING);
            $this->executeDeploy($execution);
        } catch (\Throwable $e) {
            Log::error('ContentAgent failed', ['execution_id' => $execution->id, 'error' => $e->getMessage()]);
            $this->handleAgentFailure($execution, AgentExecution::AGENT_CONTENT, $e);
        }
    }

    private function executeDeploy(AgentExecution $execution): void
    {
        $execution->current_agent = AgentExecution::AGENT_DEPLOY;
        $execution->save();

        try {
            $output = $this->deployAgent->execute($execution);
            $execution->saveAgentOutput(AgentExecution::AGENT_DEPLOY, $output, AgentExecution::STATE_REVIEWING);
            $this->executeReview($execution);
        } catch (\Throwable $e) {
            Log::error('DeployAgent failed', ['execution_id' => $execution->id, 'error' => $e->getMessage()]);
            $this->handleAgentFailure($execution, AgentExecution::AGENT_DEPLOY, $e);
        }
    }

    private function executeReview(AgentExecution $execution): void
    {
        $execution->current_agent = AgentExecution::AGENT_REVIEW;
        $execution->save();

        try {
            $output = $this->reviewAgent->execute($execution);

            // v2.6.0: 复盘完成后判断是否需要迭代优化
            $needsIteration = (bool) ($output['needs_iteration'] ?? false);
            $workspace = \App\Models\Workspace::query()->find((int) $execution->workspace_id);
            $autoIterationEnabled = $workspace && ($workspace->config['auto_optimize_iteration'] ?? true);
            $iterationCount = (int) ($execution->input_data['iteration'] ?? 0);

            if ($needsIteration && $autoIterationEnabled && $iterationCount < 3) {
                // 回路：复盘→策略→内容→分发→复盘（新一轮迭代）
                $execution->saveAgentOutput(AgentExecution::AGENT_REVIEW, $output, AgentExecution::STATE_SCOUTING);
                $inputData = $execution->input_data ?? [];
                $inputData['iteration'] = $iterationCount + 1;
                $inputData['last_review'] = $output;
                $execution->input_data = $inputData;
                $execution->save();

                Log::info('Agent workflow iterating', [
                    'execution_id' => $execution->id,
                    'iteration' => $iterationCount + 1,
                    'reason' => $output['recommendations'] ?? 'auto',
                ]);

                $this->executeScout($execution);
                return;
            }

            // 正常完成
            $execution->saveAgentOutput(AgentExecution::AGENT_REVIEW, $output, AgentExecution::STATE_COMPLETED);
            $execution->markCompleted();

            // v2.8.0: 记录到双层记忆库
            try {
                app(\App\Services\Agent\MemoryService::class)->recordExecution($execution);
            } catch (\Throwable $e) {
                Log::warning('MemoryService: record failed (non-blocking)', ['error' => $e->getMessage()]);
            }

            // v2.9: Agent 完成后自动创建分发计划
            try {
                $contentOutput = $execution->content_output ?? [];
                $articleId = $contentOutput['article_id'] ?? null;
                $strategyOutput = $execution->strategy_output ?? [];
                $platforms = $strategyOutput['task_config']['target_platforms'] ?? [];
                if ($articleId && $platforms !== []) {
                    // 策略输出用短名(toutiao), 发布引擎用全名(toutiao_publish)
                    // 白名单：只有确认支持的平台才创建调度记录
                    $platformMap = ['toutiao' => 'toutiao_publish', 'baijiahao' => 'baijiahao_publish',
                        'xiaohongshu' => 'xiaohongshu_publish', 'sohu' => 'sohu_publish'];
                    $scheduled = 0;
                    $skipped = [];
                    foreach ($platforms as $p) {
                        if (! isset($platformMap[$p])) {
                            $skipped[] = $p;
                            continue;
                        }
                        \App\Models\PublishingSchedule::create([
                            'workspace_id' => (int) $execution->workspace_id,
                            'article_id' => (int) $articleId,
                            'platform' => $platformMap[$p],
                            'scheduled_at' => now(),
                            'status' => 'pending',
                        ]);
                        $scheduled++;
                    }
                    if ($skipped !== []) {
                        Log::warning('Agent: skipped unsupported platforms', [
                            'platforms' => $skipped,
                            'execution_id' => $execution->id,
                        ]);
                    }
                    Log::info('Agent: auto-scheduled publishing', [
                        'execution_id' => $execution->id,
                        'article_id' => $articleId,
                        'platforms' => $platforms,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('Agent: auto-schedule failed (non-blocking)', ['error' => $e->getMessage()]);
            }

            Log::info('Agent workflow completed', [
                'execution_id' => $execution->id,
                'workspace_id' => $execution->workspace_id,
                'iterations' => $iterationCount,
            ]);
        } catch (\Throwable $e) {
            Log::error('ReviewAgent failed', ['execution_id' => $execution->id, 'error' => $e->getMessage()]);
            $this->handleAgentFailure($execution, AgentExecution::AGENT_REVIEW, $e);
        }
    }

    /**
     * Agent 执行失败处理：重试或标记失败。
     */
    private function handleAgentFailure(AgentExecution $execution, string $agentType, \Throwable $e): void
    {
        $execution->retry_count++;

        if ($execution->retry_count <= $execution->max_retries) {
            Log::warning("Agent retry {$execution->retry_count}/{$execution->max_retries}", [
                'execution_id' => $execution->id,
                'agent' => $agentType,
            ]);
            $execution->save();
            // 延迟重试
            sleep(min($execution->retry_count * 2, 10));
            $this->advanceFrom($execution);
            return;
        }

        $execution->markFailed($agentType, [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }
}
