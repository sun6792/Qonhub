<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceAiTokenQuota extends Model
{
    protected $table = 'workspace_ai_token_quotas';

    protected $fillable = [
        'workspace_id',
        'provider_code',
        'quota_monthly',
        'used_this_month',
        'reset_at',
    ];

    protected function casts(): array
    {
        return [
            'quota_monthly' => 'integer',
            'used_this_month' => 'integer',
            'reset_at' => 'datetime',
        ];
    }
}
