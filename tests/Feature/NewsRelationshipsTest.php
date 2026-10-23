<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsSource;
use App\Services\NewsLegacyBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NewsRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.fallback_locale', 'en');
    }

    /**
     * Seed rows shaped exactly like Task 01 data: no relations, only
     * the legacy string columns filled in.
     */
    protected function createLegacyRows(): array
    {
        $now = '2026-01-01 10:00:00';

        DB::table('news')->insert([
            [
                'title' => 'Legacy data preserved first',
                'slug' => 'legacy-data-preserved-first',
                'category' => 'Technology',
                'source_name' => 'Tech Daily',
                'source_url' => 'https://example.com/legacy-first',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Legacy data preserved second',
                'slug' => 'legacy-data-preserved-second',
                'category' => 'business',
                'source_name' => null,
                'source_url' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'title' => 'Legacy data preserved third',
                'slug' => 'legacy-data-preserved-third',
                'category' => '',
                'source_name' => '   ',
                'source_url' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        return array_map(
            fn ($row) => $row->id,
            DB::table('news')->orderBy('id')->get('id')->all(),
        );
    }

    public function test_backfill_links_existing_articles_and_preserves_legacy_data(): void
    {
        $ids = $this->createLegacyRows();

        app(NewsLegacyBackfillService::class)->run();

        // One category per distinct non-empty legacy value: "Technology"
        // and "business" are two categories (case is preserved as text).
        $this->assertDatabaseCount('news_categories', 3); // Technology, business, Uncategorized

        $this->assertDatabaseHas('news_categories', ['name' => 'Technology', 'slug' => 'technology']);
        $this->assertDatabaseHas('news_categories', ['name' => 'business', 'slug' => 'business']);
        $this->assertDatabaseHas('news_categories', ['name' => 'Uncategorized', 'slug' => 'uncategorized']);

        // One source for the single non-blank legacy source_name.
        $this->assertDatabaseCount('news_sources', 1);
        $this->assertDatabaseHas('news_sources', ['name' => 'Tech Daily', 'slug' => 'tech-daily']);

        // Existing record: linked to its category and source.
        $this->assertDatabaseHas('news', [
            'id' => $ids[0],
            'title' => 'Legacy data preserved first',
            'category' => 'Technology',
            'source_name' => 'Tech Daily',
            'source_url' => 'https://example.com/legacy-first',
            'normalized_title' => 'legacy data preserved first',
        ]);

        // Blank-category records fall back to Uncategorized.
        $uncategorizedId = DB::table('news_categories')->where('slug', 'uncategorized')->value('id');

        $this->assertDatabaseHas('news', [
            'id' => $ids[2],
            'category' => '',
            'news_category_id' => $uncategorizedId,
            'news_source_id' => null,
        ]);

        // Blank source values are handled: the FK stays null.
        $this->assertDatabaseHas('news', [
            'id' => $ids[1],
            'news_source_id' => null,
        ]);
    }

    public function test_backfill_is_idempotent_and_avoids_duplicate_categories(): void
    {
        $this->createLegacyRows();

        app(NewsLegacyBackfillService::class)->run();
        app(NewsLegacyBackfillService::class)->run();

        $this->assertDatabaseCount('news_categories', 3);
        $this->assertDatabaseCount('news_sources', 1);
    }

    public function test_legacy_timestamps_are_untouched_by_backfill(): void
    {
        $this->createLegacyRows();

        app(NewsLegacyBackfillService::class)->run();

        $updatedAt = DB::table('news')->where('slug', 'legacy-data-preserved-first')->value('updated_at');

        $this->assertSame('2026-01-01 10:00:00', $updatedAt);
    }

    public function test_backfill_generates_unique_slugs_for_collisions(): void
    {
        $now = now();
        DB::table('news')->insert([
            ['title' => 'A', 'slug' => 'a', 'category' => 'Feature Focus', 'created_at' => $now, 'updated_at' => $now],
            ['title' => 'B', 'slug' => 'b', 'category' => 'Feature  Focus', 'created_at' => $now, 'updated_at' => $now],
        ]);

        app(NewsLegacyBackfillService::class)->run();

        $slugs = DB::table('news_categories')
            ->whereRaw('LOWER(name) IN (?, ?)', ['feature focus', 'feature  focus'])
            ->pluck('slug')
            ->sort()
            ->values();

        $this->assertSame(['feature-focus', 'feature-focus-2'], $slugs->all());
    }

    public function test_news_belongs_to_category_and_source(): void
    {
        $category = NewsCategory::factory()->create();
        $source = NewsSource::factory()->create();

        $news = News::factory()->for($category, 'category')->for($source, 'source')->create();

        $news->refresh();

        $this->assertSame($category->getKey(), $news->news_category_id);
        $this->assertSame($source->getKey(), $news->news_source_id);

        // Relationship access resolves the related models.
        $this->assertTrue($news->category()->first()->is($category));
        $this->assertSame($category->getKey(), $news->newsCategory->getKey());
        $this->assertSame($source->getKey(), $news->source->getKey());
    }

    public function test_news_category_relation_access_and_legacy_value_coexist(): void
    {
        $category = NewsCategory::factory()->create();

        $news = News::factory()->create([
            'category' => 'technology-legacy-value',
        ]);

        $news->news_category_id = $category->getKey();
        $news->saveQuietly();

        $news = $news->refresh();

        // Property access keeps returning the legacy string, exactly as
        // plain Eloquent would for any other column.
        $this->assertSame('technology-legacy-value', $news->category);

        // Relation access goes through the alias property or the
        // spec-required relation method.
        $this->assertInstanceOf(NewsCategory::class, $news->newsCategory);
        $this->assertTrue($news->newsCategory->is($category));
        $this->assertTrue($news->category()->first()->is($category));

        $this->assertDatabaseHas('news', [
            'id' => $news->getKey(),
            'category' => 'technology-legacy-value',
            'news_category_id' => $category->getKey(),
        ]);
    }

    public function test_news_source_relation_resolves_through_property_access(): void
    {
        $source = NewsSource::factory()->create();

        $news = News::factory()->create([
            'news_source_id' => $source->getKey(),
        ]);

        // There is no legacy `source` column, so property access resolves
        // the relation directly.
        $this->assertTrue($news->source->is($source));
        $this->assertTrue($news->newsSource->is($source));
        $this->assertTrue($news->source()->first()->is($source));
    }

    public function test_news_without_relations_stores_null_foreign_keys(): void
    {
        $news = News::factory()->create();

        $this->assertNull($news->news_category_id);
        $this->assertNull($news->news_source_id);

        $this->assertNull($news->newsCategory);
        $this->assertNull($news->newsSource);
        $this->assertNull($news->source);

        // Property access for `category` returns the legacy string value
        // stored in the column (the factory fills it with a random topic).
        $this->assertIsString($news->category);
        $this->assertNotSame('', $news->category);
    }
}
