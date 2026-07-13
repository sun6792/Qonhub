<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI 品牌竞争力分析 — 竞品模型。
 *
 * 每个工作空间可配置 1-3 个竞品品牌，
 * 用于在 AI 搜索中进行品牌提及对比分析。
 */
class AiCompetitor extends Model
{
    protected $table = 'ai_competitors';

    protected $fillable = [
        'workspace_id',
        'brand_name',
        'brand_website',
        'status',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
