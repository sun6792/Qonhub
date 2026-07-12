<?php

/**
 * 后台管理员（表 `admins`）。
 *
 * Blade 后台与 API 审计共用；会话登录使用 `admin` guard。密码 `hashed` cast；`name` 访问器供界面展示。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'admins';

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $fillable = [
        'username',
        'password',
        'email',
        'display_name',
        'role',
        'status',
        'created_by',
        'last_login',
        'welcome_seen_version',
        'welcome_dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_login' => 'datetime',
            'welcome_dismissed_at' => 'datetime',
            'created_by' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /**
     * 顶栏等使用 `name` 展示。
     */
    public function getNameAttribute(): string
    {
        $display = trim((string) $this->display_name);

        return $display !== '' ? $display : (string) $this->username;
    }

    /**
     * 统一判断超级管理员角色，兼容历史脏值 superadmin。
     */
    public function isSuperAdmin(): bool
    {
        $role = trim(strtolower((string) ($this->role ?? '')));

        return in_array($role, ['super_admin', 'superadmin'], true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(AdminActivityLog::class, 'admin_id');
    }

    public function articleReviews(): HasMany
    {
        return $this->hasMany(ArticleReview::class, 'admin_id');
    }

    /**
     * 运营师绑定的工作空间（通过 operator_workspaces 中间表）。
     */
    public function operatorWorkspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'operator_workspaces', 'admin_id', 'workspace_id')
            ->withPivot('role', 'last_accessed_at')
            ->withTimestamps();
    }

    /**
     * 获取需要隔离过滤的 workspace ID 列表。
     * 超管返回空数组（无需过滤），运营师返回已绑定的 workspace ID 数组。
     *
     * @return list<int>|null  空数组=没有任何workspace权限，null=超管无需过滤
     */
    public function scopedWorkspaceIds(): ?array
    {
        if ($this->isSuperAdmin()) {
            return null; // null = 不过滤，看全部
        }

        return DB::table('operator_workspaces')
            ->where('admin_id', (int) $this->id)
            ->pluck('workspace_id')
            ->map(fn ($v) => (int) $v)
            ->toArray();
    }
}
