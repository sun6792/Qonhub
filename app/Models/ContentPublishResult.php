<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPublishResult extends Model
{
    protected $table = 'content_publish_results';

    protected $fillable = [
        'content_publish_task_id', 'workspace_id', 'article_id',
        'platform_key', 'platform_type', 'publisher_account_id',
        'status', 'remote_article_id', 'remote_article_url', 'certify_url', 'remote_status',
        'remote_response', 'error_code', 'error_message',
        'retry_count', 'max_retries',
        'sent_title', 'sent_content_preview',
        'anchor_certification_id',
        'execution_engine', 'executor_ip', 'duration_ms',
        'sent_at', 'completed_at',
    ];

    protected $casts = [
        'remote_response' => 'array',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ContentPublishTask::class, 'content_publish_task_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ContentPublisherAccount::class, 'publisher_account_id');
    }

    public function anchorCertification(): BelongsTo
    {
        return $this->belongsTo(EnterpriseAnchorCertification::class, 'anchor_certification_id');
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function canRetry(): bool
    {
        return $this->status === 'failed' && $this->retry_count < $this->max_retries;
    }
}
