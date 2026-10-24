<?php

namespace App\Models;

use App\Enums\NewsStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class News extends Model
{
    /** @use HasFactory<NewsFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'news';

    /**
     * Default attribute values, kept in sync with the database defaults.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'category' => 'technology',
        'status' => 'draft',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'content',
        'summary',
        'source_name',
        'source_url',
        'image',
        'category',
        'status',
        'published_at',
        'news_category_id',
        'news_source_id',
        'normalized_source_url',
        'normalized_title',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NewsStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * The structured category this article belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(NewsCategory::class, 'news_category_id');
    }

    /**
     * The structured source this article was imported from.
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class, 'news_source_id');
    }

    /**
     * Filament-bound alias of the `category` relation, named after the
     * real foreign key.
     *
     * Filament resolves relationship names with `hasAttribute()` before
     * `isRelation()`, so the legacy `category` string column would make
     * relationship selects and filters resolve to null. The alias has
     * no column conflict and behaves identically.
     */
    public function newsCategory(): BelongsTo
    {
        return $this->category();
    }

    /**
     * Filament-bound alias of the `source` relation, named after the
     * real foreign key for symmetry.
     */
    public function newsSource(): BelongsTo
    {
        return $this->source();
    }

    public function videoScripts(): HasMany
    {
        return $this->hasMany(VideoScript::class);
    }

    public function newsScore(): HasOne
    {
        return $this->hasOne(NewsScore::class);
    }

    public function storyClusters(): BelongsToMany
    {
        return $this->belongsToMany(StoryCluster::class, 'story_cluster_news')
            ->withPivot(['similarity', 'is_primary'])
            ->withTimestamps();
    }
}
