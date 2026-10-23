<?php

namespace App\Services;

use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsSource;
use Illuminate\Support\Str;

class NewsLegacyBackfillService
{
    /**
     * Populate category/source relations for all existing articles.
     *
     * Safe to run multiple times: categories and sources are matched
     * case-insensitively, slugs are deduplicated with suffixes, and
     * legacy string columns are never modified. Article rows keep
     * their timestamps untouched.
     */
    public function run(): void
    {
        $categoryIds = $this->createCategoriesFromLegacyValues();
        $sourceIds = $this->createSourcesFromLegacyValues();
        $uncategorizedId = $this->findOrCreateCategory(__('Uncategorized'));

        News::query()
            ->orderBy('id')
            ->chunkById(100, function ($newsArticles) use ($categoryIds, $sourceIds, $uncategorizedId): void {
                foreach ($newsArticles as $news) {
                    $legacyCategoryName = trim((string) $news->category);
                    $legacySourceName = trim((string) $news->source_name);

                    $news->timestamps = false;

                    $news->forceFill([
                        'news_category_id' => filled($legacyCategoryName)
                            ? $categoryIds[$legacyCategoryName]
                            : $uncategorizedId,
                        'news_source_id' => $sourceIds[$legacySourceName] ?? null,
                        'normalized_source_url' => NewsDuplicateService::normalizeUrl($news->source_url),
                        'normalized_title' => NewsDuplicateService::normalizeTitle($news->title),
                    ])->save();
                }
            });
    }

    /**
     * Create one NewsCategory per distinct legacy `category` value.
     *
     * Case-insensitively matched to avoid duplicate categories that
     * differ only by letter case.
     *
     * @return array<string, int>
     */
    protected function createCategoriesFromLegacyValues(): array
    {
        $categoryIds = [];

        News::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->get(['category'])
            ->each(function ($row) use (&$categoryIds): void {
                $name = trim((string) $row->category);

                $categoryIds[$name] = $this->findOrCreateCategory($name);
            });

        return $categoryIds;
    }

    /**
     * Create one NewsSource per distinct non-blank legacy `source_name`.
     *
     * Null and blank source values are ignored; such articles keep
     * `news_source_id` set to null.
     *
     * @return array<string, int>
     */
    protected function createSourcesFromLegacyValues(): array
    {
        $sourceIds = [];

        News::query()
            ->whereNotNull('source_name')
            ->where('source_name', '!=', '')
            ->whereRaw("TRIM(COALESCE(source_name, '')) != ''")
            ->distinct()
            ->get(['source_name'])
            ->each(function ($row) use (&$sourceIds): void {
                $name = trim((string) $row->source_name);

                $sourceIds[$name] = $this->findOrCreateSource($name);
            });

        return $sourceIds;
    }

    /**
     * Find an existing category by name, or safely create it.
     */
    protected function findOrCreateCategory(string $name): int
    {
        $category = NewsCategory::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first()
            ?? NewsCategory::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name, NewsCategory::class),
            ]);

        return (int) $category->id;
    }

    /**
     * Find an existing source by name, or safely create it.
     */
    protected function findOrCreateSource(string $name): int
    {
        $source = NewsSource::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first()
            ?? NewsSource::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name, NewsSource::class),
            ]);

        return (int) $source->id;
    }

    /**
     * Generate a slug that is unique within the given model's table.
     */
    protected function uniqueSlug(string $name, string $modelClass): string
    {
        $baseSlug = $slug = Str::slug($name);
        $suffix = 2;

        while ($modelClass::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-".$suffix++;
        }

        return $slug;
    }
}
