<?php

namespace App\Models;

use App\Enums\StoryClusterStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoryCluster extends Model
{
    protected $table = 'story_clusters';

    protected $fillable = [
        'fingerprint',
        'canonical_title',
        'canonical_summary',
        'fact_sheet',
        'status',
        'confidence',
        'window_start',
        'window_end',
        'source_count',
    ];

    protected function casts(): array
    {
        return [
            'fact_sheet' => 'array',
            'status' => StoryClusterStatus::class,
            'confidence' => 'float',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'source_count' => 'integer',
        ];
    }

    public function news(): BelongsToMany
    {
        return $this->belongsToMany(News::class, 'story_cluster_news')
            ->withPivot(['similarity', 'is_primary'])
            ->withTimestamps();
    }

    public function members(): HasMany
    {
        return $this->hasMany(StoryClusterNews::class);
    }

    public function primaryNews(): ?News
    {
        $primary = $this->news->firstWhere(fn (News $news): bool => (bool) $news->pivot->is_primary);

        return $primary ?? $this->news->first();
    }

    public function maxCompositeScore(): float
    {
        return (float) $this->news
            ->max(fn (News $news): float => (float) ($news->newsScore?->composite_score ?? 0));
    }

    /**
     * @return list<int>
     */
    public function newsIds(): array
    {
        return $this->news
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
