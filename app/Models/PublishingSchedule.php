<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublishingSchedule extends Model
{
    protected $table = 'publishing_schedules';

    protected $fillable = [
        'workspace_id', 'article_id', 'platform',
        'scheduled_at', 'status', 'error_message', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function workspace(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function article(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
