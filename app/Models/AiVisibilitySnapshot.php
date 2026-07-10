<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI引用追踪每日聚合快照，用于趋势图展示。
 */
class AiVisibilitySnapshot extends Model
{
    protected $fillable = [
        'workspace_id', 'snapshot_date', 'ai_platform',
        'total_queries', 'mentioned_count', 'visibility_score',
        'previous_score', 'top_keywords', 'detail',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'total_queries' => 'integer',
        'mentioned_count' => 'integer',
        'visibility_score' => 'decimal:2',
        'previous_score' => 'decimal:2',
        'top_keywords' => 'array',
        'detail' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
