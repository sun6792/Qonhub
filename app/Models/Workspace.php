<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $access_token
 * @property string $status
 */
class Workspace extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'logo_url',
        'access_token', 'owner_admin_id',
        'client_company_name', 'client_contact_name',
        'client_email', 'client_phone',
        'brand_keywords', 'config',
        'status', 'last_activity_at',
    ];

    protected $casts = [
        'brand_keywords' => 'array',
        'config' => 'array',
        'last_activity_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Workspace $workspace): void {
            if (empty($workspace->slug)) {
                $workspace->slug = Str::slug($workspace->name).'-'.Str::lower(Str::random(6));
            }
            if (empty($workspace->access_token)) {
                $workspace->access_token = Str::random(40);
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function operators(): HasMany
    {
        return $this->hasMany(OperatorWorkspace::class);
    }

    public function platformAccounts(): HasMany
    {
        return $this->hasMany(ClientPlatformAccount::class);
    }

    public function visibilitySnapshots(): HasMany
    {
        return $this->hasMany(AiVisibilitySnapshot::class);
    }

    /**
     * @return MorphToMany
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(WorkspaceAssignment::class);
    }

    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->save();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function clientPortalUrl(): string
    {
        return rtrim((string) config('app.url'), '/')
            .'/client/'.$this->slug
            .'?token='.$this->access_token;
    }

    /**
     * @return array<string>
     */
    public function brandKeywordList(): array
    {
        $keywords = $this->brand_keywords;

        return is_array($keywords) ? array_values(array_filter($keywords, 'is_string')) : [];
    }
}
