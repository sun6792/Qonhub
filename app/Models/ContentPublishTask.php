<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentPublishTask extends Model
{
    protected $table = 'content_publish_tasks';

    protected $fillable = [
        'workspace_id', 'task_name', 'status', 'type', 'progress_percent',
        'armory_publish_log_id', 'article_ids', 'platform_keys',
        'total_articles', 'total_platforms', 'total_jobs',
        'avg_geo_score', 'geo_score_details',
        'completed_jobs', 'failed_jobs',
        'keyword_group_id', 'run_mode', 'last_auto_run_at',
        'use_smart_scheduling', 'use_content_rewrite', 'rewrite_mode',
        'min_publish_interval_seconds', 'max_concurrent_accounts',
        'created_by_admin_id', 'requested_by_client_user_id',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'article_ids' => 'array',
        'platform_keys' => 'array',
        'geo_score_details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_auto_run_at' => 'datetime',
        'use_smart_scheduling' => 'boolean',
        'use_content_rewrite' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ContentPublishResult::class, 'content_publish_task_id');
    }

    public function updateProgress(): void
    {
        $oldStatus = $this->status;
        $completed = $this->results()->whereIn('status', ['success', 'failed'])->count();
        $newStatus = $completed >= $this->total_jobs
            ? ($this->results()->where('status', 'failed')->count() > 0 ? 'partial_failed' : 'completed')
            : 'running';

        $this->forceFill([
            'completed_jobs' => $this->results()->where('status', 'success')->count(),
            'failed_jobs' => $this->results()->where('status', 'failed')->count(),
            'progress_percent' => $this->total_jobs > 0
                ? (int) round($completed / $this->total_jobs * 100)
                : 0,
            'status' => $newStatus,
        ])->save();

        // v2.6.0: 发布完成后触发 Scout 收录检测
        if ($oldStatus !== 'completed' && $newStatus === 'completed') {
            $this->triggerPostDeployScout();
        }
    }

    /**
     * v2.6.0: 发布完成后自动触发 Scout 检测。
     * 受 workspace 级别开关控制，默认开启。
     */
    private function triggerPostDeployScout(): void
    {
        // workspace 级别开关（默认开启）
        $workspace = $this->workspace;
        if ($workspace && ! ($workspace->config['auto_deploy_scout'] ?? true)) {
            return;
        }

        $articleIds = is_array($this->article_ids) ? $this->article_ids : [];
        if ($articleIds === []) {
            return;
        }

        \App\Jobs\TriggerPostDeployScout::dispatch(
            (int) $this->workspace_id,
            array_map('intval', $articleIds),
        );
    }
}
