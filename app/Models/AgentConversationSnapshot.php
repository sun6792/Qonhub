<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentConversationSnapshot extends Model
{
    protected $table = 'agent_conversation_snapshots';

    protected $fillable = [
        'agent_execution_id',
        'workspace_id',
        'task_id',
        'ai_provider_code',
        'model_id',
        'prompt',
        'response_text',
        'cited_urls',
        'geo_score',
        'brand_mentioned',
        'brand_name',
        'snapshot_at',
    ];

    protected function casts(): array
    {
        return [
            'cited_urls' => 'json',
            'brand_mentioned' => 'boolean',
            'snapshot_at' => 'datetime',
        ];
    }

    public function agentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
