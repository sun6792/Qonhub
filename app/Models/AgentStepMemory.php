<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentStepMemory extends Model
{
    protected $table = 'agent_step_memories';

    protected $fillable = [
        'workspace_id', 'execution_id', 'agent_type',
        'input_digest', 'output_digest',
        'success', 'metrics', 'tags', 'pattern_key',
    ];

    protected function casts(): array
    {
        return [
            'metrics' => 'json',
            'tags' => 'json',
            'success' => 'boolean',
        ];
    }

    public function workspace(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
