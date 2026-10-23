<?php

namespace App\Models;

use Database\Factories\NewsCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsCategory extends Model
{
    /** @use HasFactory<NewsCategoryFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'icon',
        'is_active',
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
        ];
    }

    /**
     * Articles assigned to this category.
     */
    public function news(): HasMany
    {
        return $this->hasMany(News::class, 'news_category_id');
    }

    protected static function booted(): void
    {
        // Deleting a category that still owns articles would orphan or
        // alter them; the guard keeps deletes explicit and safe.
        static::deleting(function (NewsCategory $category) {
            if (! $category->exists || $category->news()->doesntExist()) {
                return;
            }

            throw new \DomainException("The category [{$category->name}] still has articles and cannot be deleted.");
        });
    }
}
