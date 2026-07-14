<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;

/**
 * 应用控制器基类。
 *
 * Web、API 等具体控制器可继承此类；公共行为（如授权）宜通过中间件或 Trait 扩展。
 */
abstract class Controller
{
    /**
     * 按运营师绑定的 workspace 过滤素材列表。
     * 超管不过滤（看全部）；运营师只看自己绑定的 workspace 下的数据。
     *
     * @param  Builder  $query  待过滤的 Eloquent 查询
     * @param  string   $assignableType  workspace_assignments 中的 morph 类型（如 KeywordLibrary::class）
     */
    protected function scopeByOperatorWorkspaces(Builder $query, string $assignableType): void
    {
        $admin = auth('admin')->user();
        if (! $admin) {
            return;
        }

        $workspaceIds = $admin->scopedWorkspaceIds();

        // null = 超管，不过滤
        if ($workspaceIds === null) {
            return;
        }

        // 空数组 = 运营师未绑定任何 workspace，什么都看不到
        if ($workspaceIds === []) {
            $query->whereRaw('1=0');
            return;
        }

        $query->whereIn('id', function ($sub) use ($workspaceIds, $assignableType) {
            $sub->select('assignable_id')
                ->from('workspace_assignments')
                ->whereIn('workspace_id', $workspaceIds)
                ->where('assignable_type', $assignableType);
        });
    }

    /**
     * 验证运营师是否有权访问指定素材资源。
     * 超管直接通过；运营师需该资源属于其绑定的 workspace，否则 abort 403。
     */
    protected function authorizeOperatorAccess(int $resourceId, string $assignableType): void
    {
        $admin = auth('admin')->user();
        if (! $admin || $admin->isSuperAdmin()) {
            return;
        }

        $workspaceIds = \App\Models\Admin::query()->whereKey((int) $admin->id)->first()?->scopedWorkspaceIds() ?? [];
        if ($workspaceIds === []) {
            abort(403);
        }

        $exists = \Illuminate\Support\Facades\DB::table('workspace_assignments')
            ->where('assignable_type', $assignableType)
            ->where('assignable_id', $resourceId)
            ->whereIn('workspace_id', $workspaceIds)
            ->exists();

        if (! $exists) {
            abort(403);
        }
    }

    /**
     * 验证运营师是否有权访问指定 workspace。
     * 超管直接通过；运营师需该 workspace 属于其 operator_workspaces 绑定，否则 abort 403。
     */
    protected function authorizeWorkspaceAccess(int $workspaceId): void
    {
        $admin = auth('admin')->user();
        if (! $admin || $admin->isSuperAdmin()) {
            return;
        }

        $workspaceIds = \App\Models\Admin::query()->whereKey((int) $admin->id)->first()?->scopedWorkspaceIds() ?? [];
        if ($workspaceIds === []) {
            abort(403);
        }

        if (! in_array($workspaceId, $workspaceIds, true)) {
            abort(403);
        }
    }

    /**
     * 新建素材后自动分配到运营师的 workspace。
     * 超管不自动分配；运营师自动绑到其所有 workspace 上。
     */
    protected function assignToOperatorWorkspaces(int $resourceId, string $assignableType): void
    {
        $admin = auth('admin')->user();
        if (! $admin || $admin->isSuperAdmin()) {
            return;
        }

        $workspaceIds = \App\Models\Admin::query()->whereKey((int) $admin->id)->first()?->scopedWorkspaceIds() ?? [];
        if ($workspaceIds === [] || $workspaceIds === null) {
            return;
        }

        $now = now();
        $rows = array_map(fn ($wsId) => [
            'workspace_id' => $wsId,
            'assignable_type' => $assignableType,
            'assignable_id' => $resourceId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $workspaceIds);

        \Illuminate\Support\Facades\DB::table('workspace_assignments')->insert($rows);
    }
}
