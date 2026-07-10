<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $admin_id
 * @property int $workspace_id
 * @property string $role
 */
class OperatorWorkspace extends Model
{
    protected $fillable = [
        'admin_id', 'workspace_id', 'role', 'last_accessed_at',
    ];

    protected $casts = [
        'last_accessed_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public static function assignOperator(int $adminId, int $workspaceId, string $role = 'operator'): self
    {
        return self::query()->updateOrCreate(
            ['admin_id' => $adminId, 'workspace_id' => $workspaceId],
            ['role' => $role]
        );
    }

    public function touchAccess(): void
    {
        $this->forceFill(['last_accessed_at' => now()])->save();
    }
}
