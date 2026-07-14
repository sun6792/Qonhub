<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use SoftDeletes;

    protected $table = 'articles';

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'content',
        'category_id',
        'author_id',
        'task_id',
        'original_keyword',
        'keywords',
        'meta_description',
        'status',
        'review_status',
        'view_count',
        'is_ai_generated',
        'geo_score',
        'geo_grade',
        'geo_score_data',
        'is_hot',
        'is_featured',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'author_id' => 'integer',
            'task_id' => 'integer',
            'view_count' => 'integer',
            'is_ai_generated' => 'integer',
            'geo_score' => 'integer',
            'geo_score_data' => 'json',
            'is_hot' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * 删除/恢复时同步更新任务的已创建计数。
     */
    protected static function booted(): void
    {
        $syncCount = function (Article $article) {
            if ((int) $article->task_id > 0) {
                $actual = (int) Article::query()
                    ->where('task_id', (int) $article->task_id)
                    ->whereNull('deleted_at')
                    ->count();
                \App\Models\Task::query()
                    ->whereKey((int) $article->task_id)
                    ->update(['created_count' => $actual]);
            }
        };

        static::deleted(fn (Article $a) => $syncCount($a));
        static::forceDeleted(fn (Article $a) => $syncCount($a));
        static::restored(fn (Article $a) => $syncCount($a));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function articleImages(): HasMany
    {
        return $this->hasMany(ArticleImage::class, 'article_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ArticleReview::class, 'article_id');
    }

    public function taskRuns(): HasMany
    {
        return $this->hasMany(TaskRun::class, 'article_id');
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(ArticleDistribution::class, 'article_id');
    }

    public function syncedRemoteDistributions(): HasMany
    {
        return $this->hasMany(ArticleDistribution::class, 'article_id')
            ->where('status', 'synced')
            ->where('action', '!=', 'delete')
            ->whereNotNull('remote_url')
            ->whereRaw("TRIM(remote_url) <> ''")
            ->where(function ($query): void {
                $query->whereRaw('LOWER(TRIM(remote_url)) LIKE ?', ['http://%'])
                    ->orWhereRaw('LOWER(TRIM(remote_url)) LIKE ?', ['https://%']);
            })
            ->orderByDesc('updated_at');
    }

    /**
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNull('deleted_at');
    }

    /**
     * GEO 评分（优先取存储值，未有则实时计算）。
     */
    public function getGeoScoreAttribute(): ?int
    {
        // 优先返回已持久化的评分（生成时已计算）
        $stored = $this->attributes['geo_score'] ?? null;
        if ($stored !== null) {
            return (int) $stored;
        }
        // 旧文章无存储值，按需计算（仅此一次，后续不缓存）
        if (empty($this->title) && empty($this->content)) return null;
        try {
            $scorer = app(\App\Services\GeoFlow\GeoContentScorer::class);
            return $scorer->quickScore($this->title ?? '', $this->content ?? '');
        } catch (\Throwable) { return null; }
    }

    public function getGeoGradeAttribute(): string
    {
        $s = $this->getGeoScoreAttribute();
        if ($s === null) return '—';
        return match (true) { $s >= 85 => 'A', $s >= 70 => 'B', $s >= 50 => 'C', $s >= 30 => 'D', default => 'F' };
    }
}
