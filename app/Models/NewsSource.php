<?php

namespace App\Models;

use Database\Factories\NewsSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsSource extends Model
{
    /** @use HasFactory<NewsSourceFactory> */
    use HasFactory;

    /**
     * Supported source languages, keyed by their codes.
     *
     * @var array<string, string>
     */
    public const LANGUAGES = [
        'en' => 'English',
        'ar' => 'Arabic',
        'fr' => 'French',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'website_url',
        'rss_url',
        'description',
        'logo',
        'language',
        'is_active',
        'last_fetched_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_fetched_at' => 'datetime',
        ];
    }

    /**
     * Articles assigned to this source.
     */
    public function news(): HasMany
    {
        return $this->hasMany(News::class, 'news_source_id');
    }

    protected static function booted(): void
    {
        // Deleting a source that still owns articles would orphan or
        // alter them; the guard keeps deletes explicit and safe.
        static::deleting(function (NewsSource $source) {
            if (! $source->exists || $source->news()->doesntExist()) {
                return;
            }

            throw new \DomainException("The source [{$source->name}] still has articles and cannot be deleted.");
        });
    }
}
