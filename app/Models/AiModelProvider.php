<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelProvider extends Model
{
    protected $table = 'ai_model_providers';

    protected $fillable = [
        'provider_code',
        'provider_name',
        'api_base_url',
        'adapter_class',
        'is_active',
        'failover_priority',
        'config_json',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'failover_priority' => 'integer',
            'config_json' => 'json',
        ];
    }
}
