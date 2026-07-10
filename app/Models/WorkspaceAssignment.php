<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 多态桥表：将任意已有资源（Task/Article/KnowledgeBase等）关联到工作空间。
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $assignable_type
 * @property int $assignable_id
 */
class WorkspaceAssignment extends Model
{
    protected $fillable = [
        'workspace_id', 'assignable_type', 'assignable_id', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * 将指定资源挂入工作空间。
     */
    public static function assign(int $workspaceId, string $type, int $id, int $sortOrder = 0): self
    {
        return self::query()->updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'assignable_type' => $type,
                'assignable_id' => $id,
            ],
            ['sort_order' => $sortOrder]
        );
    }

    /**
     * 获取某个工作空间下指定类型的所有资源ID。
     *
     * @return list<int>
     */
    public static function assignedIds(int $workspaceId, string $type): array
    {
        return self::query()
            ->where('workspace_id', $workspaceId)
            ->where('assignable_type', $type)
            ->pluck('assignable_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * 批量挂入资源。
     *
     * @param  list<int>  $ids
     */
    public static function assignMany(int $workspaceId, string $type, array $ids): void
    {
        $inserts = array_map(static fn (int $id, int $index): array => [
            'workspace_id' => $workspaceId,
            'assignable_type' => $type,
            'assignable_id' => $id,
            'sort_order' => $index,
            'created_at' => now(),
            'updated_at' => now(),
        ], $ids, array_keys($ids));

        self::query()->upsert(
            $inserts,
            ['workspace_id', 'assignable_type', 'assignable_id'],
            ['sort_order', 'updated_at']
        );
    }
}
