<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI引用追踪明细记录。
 */
class AiVisibilityCheck extends Model
{
    protected $fillable = [
        'workspace_id', 'ai_platform', 'query_keyword', 'query_text',
        'mentioned', 'mention_type', 'response_snippet', 'citation_position',
        'raw_response_meta', 'api_cost', 'duration_ms', 'checked_at',
    ];

    protected $casts = [
        'mentioned' => 'boolean',
        'raw_response_meta' => 'array',
        'api_cost' => 'decimal:6',
        'duration_ms' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
