<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArmoryPublishLog extends Model
{
    protected $fillable = [
        'article_id', 'workspace_id', 'template_key', 'platform_key',
        'channel_id', 'rewritten_title', 'rewritten_content',
        'status', 'message', 'response_meta', 'published_by_admin_id',
    ];

    protected $casts = [
        'response_meta' => 'array',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(DistributionChannel::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'published_by_admin_id');
    }
}
