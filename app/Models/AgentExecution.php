<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentExecution extends Model
{
    protected $table = 'agent_executions';

    protected $fillable = [
        'workspace_id',
        'workflow_key',
        'current_state',
        'current_agent',
        'input_data',
        'scout_output',
        'strategy_output',
        'content_output',
        'deploy_output',
        'review_output',
        'error_data',
        'retry_count',
        'max_retries',
        'started_at',
        'completed_at',
        'triggered_by_admin_id',
        'trigger_type',
    ];

    protected function casts(): array
    {
        return [
            'input_data' => 'json',
            'scout_output' => 'json',
            'strategy_output' => 'json',
            'content_output' => 'json',
            'deploy_output' => 'json',
            'review_output' => 'json',
            'error_data' => 'json',
            'retry_count' => 'integer',
            'max_retries' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ── 状态机常量 ──
    const STATE_IDLE = 'idle';
    const STATE_SCOUTING = 'scouting';
    const STATE_PLANNING = 'planning';
    const STATE_WRITING = 'writing';
    const STATE_DEPLOYING = 'deploying';
    const STATE_REVIEWING = 'reviewing';
    const STATE_COMPLETED = 'completed';
    const STATE_FAILED = 'failed';

    // Agent 名称常量
    const AGENT_SCOUT = 'scout';
    const AGENT_STRATEGY = 'strategy';
    const AGENT_CONTENT = 'content';
    const AGENT_DEPLOY = 'deploy';
    const AGENT_REVIEW = 'review';

    // 状态流转表
    const TRANSITIONS = [
        self::STATE_IDLE => [self::STATE_SCOUTING],
        self::STATE_SCOUTING => [self::STATE_PLANNING, self::STATE_FAILED],
        self::STATE_PLANNING => [self::STATE_WRITING, self::STATE_FAILED],
        self::STATE_WRITING => [self::STATE_DEPLOYING, self::STATE_WRITING, self::STATE_FAILED],
        self::STATE_DEPLOYING => [self::STATE_REVIEWING, self::STATE_DEPLOYING, self::STATE_FAILED],
        self::STATE_REVIEWING => [self::STATE_COMPLETED, self::STATE_FAILED, self::STATE_PLANNING],
    ];

    public function transitionTo(string $newState): void
    {
        $allowed = self::TRANSITIONS[$this->current_state] ?? [];
        if (! in_array($newState, $allowed, true) && $this->current_state !== $newState) {
            throw new \RuntimeException(
                "非法状态转换: {$this->current_state} → {$newState}"
            );
        }
        $this->current_state = $newState;
        $this->save();
    }

    public function isCompleted(): bool
    {
        return $this->current_state === self::STATE_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->current_state === self::STATE_FAILED;
    }

    public function workspace(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * 保存指定 Agent 的输出并推进状态。
     */
    public function saveAgentOutput(string $agentType, array $output, string $nextState): void
    {
        $outputField = "{$agentType}_output";
        if (in_array($outputField, ['scout_output', 'strategy_output', 'content_output', 'deploy_output', 'review_output'], true)) {
            $this->$outputField = $output;
        }
        $this->current_agent = null;
        $this->retry_count = 0;
        $this->transitionTo($nextState);
        $this->save();
    }

    /**
     * 标记失败。
     */
    public function markFailed(string $agentType, array $error): void
    {
        $this->current_agent = $agentType;
        $this->error_data = $error;
        $this->transitionTo(self::STATE_FAILED);
        $this->completed_at = now();
        $this->save();
    }

    /**
     * 标记完成。
     */
    public function markCompleted(): void
    {
        $this->transitionTo(self::STATE_COMPLETED);
        $this->completed_at = now();
        $this->save();
    }
}
