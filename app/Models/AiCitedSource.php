<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCitedSource extends Model
{
    protected $table = 'ai_cited_sources';

    protected $fillable = [
        'workspace_id',
        'snapshot_id',
        'ai_platform',
        'url',
        'domain',
        'title',
        'excerpt',
        'mention_position',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(AgentConversationSnapshot::class, 'snapshot_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
