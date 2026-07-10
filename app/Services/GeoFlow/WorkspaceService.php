<?php

namespace App\Services\GeoFlow;

use App\Models\OperatorWorkspace;
use App\Models\Workspace;
use App\Models\WorkspaceAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WorkspaceService
{
    /**
     * 获取当前运营人员可访问的工作空间列表。
     *
     * @return Collection<int, Workspace>
     */
    public function listForOperator(int $adminId): Collection
    {
        return Workspace::query()
            ->whereHas('operators', function (Builder $q) use ($adminId): void {
                $q->where('admin_id', $adminId);
            })
            ->orWhere('owner_admin_id', $adminId)
            ->orderBy('last_activity_at', 'desc')
            ->orderBy('name')
            ->get();
    }

    public function create(array $data, int $ownerAdminId): Workspace
    {
        $data['owner_admin_id'] = $ownerAdminId;
        $workspace = Workspace::query()->create($data);

        OperatorWorkspace::assignOperator($ownerAdminId, (int) $workspace->id, 'operator');

        return $workspace->fresh();
    }

    public function update(Workspace $workspace, array $data): Workspace
    {
        $workspace->forceFill($data)->save();

        return $workspace->fresh();
    }

    /**
     * 将已有资源挂入工作空间。
     */
    public function assignResource(int $workspaceId, string $type, int $id): void
    {
        WorkspaceAssignment::assign($workspaceId, $type, $id);
        Workspace::query()->whereKey($workspaceId)->touchActivity();
    }

    /**
     * 批量挂入资源。
     *
     * @param  list<int>  $ids
     */
    public function assignManyResources(int $workspaceId, string $type, array $ids): void
    {
        WorkspaceAssignment::assignMany($workspaceId, $type, $ids);
        Workspace::query()->whereKey($workspaceId)->touchActivity();
    }

    /**
     * 从工作空间移除资源。
     */
    public function unassignResource(int $workspaceId, string $type, int $id): void
    {
        WorkspaceAssignment::query()
            ->where('workspace_id', $workspaceId)
            ->where('assignable_type', $type)
            ->where('assignable_id', $id)
            ->delete();
    }

    /**
     * 获取工作空间下指定类型的资源ID列表。
     *
     * @return list<int>
     */
    public function assignedIds(int $workspaceId, string $type): array
    {
        return WorkspaceAssignment::assignedIds($workspaceId, $type);
    }

    /**
     * 获取工作空间下某类型资源的查询作用域。
     */
    public function scopeByWorkspace(Builder $query, int $workspaceId, string $type): Builder
    {
        $ids = $this->assignedIds($workspaceId, $type);

        return $ids !== [] ? $query->whereIn('id', $ids) : $query->whereRaw('1 = 0');
    }

    /**
     * 给工作空间分配运营人员。
     */
    public function assignOperator(int $workspaceId, int $adminId, string $role = 'operator'): void
    {
        OperatorWorkspace::assignOperator($adminId, $workspaceId, $role);
    }

    /**
     * 移除运营人员。
     */
    public function removeOperator(int $workspaceId, int $adminId): void
    {
        OperatorWorkspace::query()
            ->where('workspace_id', $workspaceId)
            ->where('admin_id', $adminId)
            ->delete();
    }

    /**
     * 获取当前运营人员活跃的工作空间（最后访问的）。
     */
    public function currentWorkspace(int $adminId): ?Workspace
    {
        $last = OperatorWorkspace::query()
            ->where('admin_id', $adminId)
            ->orderByDesc('last_accessed_at')
            ->first();

        if ($last) {
            $last->touchAccess();

            return Workspace::query()->whereKey((int) $last->workspace_id)->first();
        }

        return $this->listForOperator($adminId)->first();
    }

    /**
     * 获取所有工作空间的超管监控统计。
     *
     * @return Collection<int, array<string,mixed>>
     */
    public function monitorStats(): Collection
    {
        return Workspace::query()
            ->with('owner')
            ->withCount([
                'assignments as task_count' => fn (Builder $q): Builder => $q->where('assignable_type', \App\Models\Task::class),
                'assignments as kb_count' => fn (Builder $q): Builder => $q->where('assignable_type', \App\Models\KnowledgeBase::class),
                'assignments as article_count' => fn (Builder $q): Builder => $q->where('assignable_type', \App\Models\Article::class),
            ])
            ->orderBy('status')
            ->orderByDesc('last_activity_at')
            ->get()
            ->map(function (Workspace $ws): array {
                return [
                    'id' => (int) $ws->id,
                    'name' => (string) $ws->name,
                    'slug' => (string) $ws->slug,
                    'status' => (string) $ws->status,
                    'owner_name' => $ws->owner?->name ?? '未分配',
                    'task_count' => (int) $ws->task_count,
                    'kb_count' => (int) $ws->kb_count,
                    'article_count' => (int) $ws->article_count,
                    'last_activity' => $ws->last_activity_at?->diffForHumans(),
                ];
            });
    }

    /**
     * 通过 slug + token 验证客户访问。
     */
    public function clientAccessCheck(string $slug, string $token): ?Workspace
    {
        $workspace = Workspace::query()
            ->where('slug', $slug)
            ->where('access_token', $token)
            ->where('status', 'active')
            ->first();

        return $workspace;
    }

    /**
     * 分页查询工作空间下的资源。
     *
     * @return LengthAwarePaginator
     */
    public function paginateAssigned(string $modelClass, int $workspaceId, int $perPage = 20): LengthAwarePaginator
    {
        $ids = $this->assignedIds($workspaceId, $modelClass);

        if ($ids === []) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        return $modelClass::query()
            ->whereIn('id', $ids)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
