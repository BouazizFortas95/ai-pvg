<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\NewsSource;
use App\Services\NewsDuplicateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NewsDuplicateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected NewsDuplicateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new NewsDuplicateService;
    }

    public function test_detects_an_existing_article_by_normalized_source_url(): void
    {
        News::factory()->create([
            'normalized_source_url' => 'https://example.com/news/story',
        ]);

        $existing = $this->service->findBySourceUrl('https://www.example.com/news/story/?utm_source=rss#top');

        $this->assertNotNull($existing);
    }

    public function test_returns_null_for_a_unique_source_url(): void
    {
        News::factory()->create([
            'normalized_source_url' => 'https://example.com/known/story',
        ]);

        $this->assertNull($this->service->findBySourceUrl('https://example.com/other/story'));
    }

    public function test_url_normalization_treats_cosmetic_variants_as_equal(): void
    {
        $base = 'https://example.com:443/News/Story';

        $normalized = NewsDuplicateService::normalizeUrl($base);

        $this->assertSame($normalized, NewsDuplicateService::normalizeUrl('http://www.example.com/News/Story/?utm_source=rss&fbclid=abc#section'));
        $this->assertSame($normalized, NewsDuplicateService::normalizeUrl('HTTPS://WWW.EXAMPLE.COM/News/Story'));
        $this->assertSame($normalized, NewsDuplicateService::normalizeUrl('https://example.com/News/Story?gclid=xyz&utm_medium=cpc&utm_source=partner&utm_term=alpha'));
        $this->assertSame($normalized, NewsDuplicateService::normalizeUrl('https://example.com/News/Story/?utm_source=x'));
    }

    public function test_url_normalization_keeps_meaningful_query_parameters(): void
    {
        $this->assertSame(
            'https://example.com/story?page=2',
            NewsDuplicateService::normalizeUrl('https://example.com/story?page=2&utm_source=rss'),
        );

        $this->assertSame(
            'https://example.com/story?page=1&sort=desc',
            NewsDuplicateService::normalizeUrl('https://example.com/story?sort=desc&page=1'),
        );
    }

    public function test_empty_and_invalid_urls_never_match(): void
    {
        News::factory()->create([
            'normalized_source_url' => null,
        ]);

        $this->assertNull(NewsDuplicateService::normalizeUrl(null));
        $this->assertNull(NewsDuplicateService::normalizeUrl(''));
        $this->assertNull(NewsDuplicateService::normalizeUrl('   '));
        $this->assertNull(NewsDuplicateService::normalizeUrl('://invalid'));
        $this->assertNull($this->service->findBySourceUrl(null));
        $this->assertNull($this->service->findBySourceUrl(''));
    }

    public function test_detects_an_existing_article_by_source_and_normalized_title(): void
    {
        $source = NewsSource::factory()->create();

        News::factory()->create([
            'news_source_id' => $source->getKey(),
            'normalized_title' => 'a quantum breakthrough',
        ]);

        $found = $this->service->findBySourceAndTitle(
            'A   QUANTUM   Breakthrough ',
            $source->getKey(),
        );

        $this->assertNotNull($found, 'Case and spacing differences should not hide a duplicates.');
    }

    public function test_identical_titles_from_different_sources_are_not_duplicates(): void
    {
        $sourceA = NewsSource::factory()->create();
        $sourceB = NewsSource::factory()->create();

        News::factory()->create([
            'news_source_id' => $sourceA->getKey(),
            'normalized_title' => 'identical headline across sources',
        ]);

        $found = $this->service->findBySourceAndTitle(
            'identical headline across sources',
            $sourceB->getKey(),
        );

        $this->assertNull($found);
    }

    public function test_missing_source_value_prevents_title_matching(): void
    {
        News::factory()->create([
            'news_source_id' => null,
            'normalized_title' => 'unattributed headline',
        ]);

        $this->assertNull(
            $this->service->findBySourceAndTitle('unattributed headline', null),
        );
    }

    public function test_existing_duplicate_records_are_found_not_deleted(): void
    {
        $source = NewsSource::factory()->create();

        // Duplicates can exist from before the unique indexes were
        // introduced. Simulate that by dropping the title-unique index
        // (rolled back with the test transaction) and inserting a pair.
        Schema::table('news', fn (Blueprint $table) => $table->dropIndex('news_source_normalized_title_unique'));

        $first = News::factory()->create([
            'news_source_id' => $source->getKey(),
            'normalized_title' => 'same headline',
        ]);

        $second = News::factory()->create([
            'news_source_id' => $source->getKey(),
            'normalized_title' => 'same headline',
        ]);

        $found = $this->service->findBySourceAndTitle('same headline', $source->getKey());

        // The oldest record is surfaced; nothing is deleted automatically.
        $this->assertTrue($found->is($first));

        $this->assertDatabaseHas('news', ['id' => $second->getKey()]);
        $this->assertDatabaseCount('news', 2);
    }

    public function test_find_existing_news_combines_url_and_title_detection(): void
    {
        $source = NewsSource::factory()->create();

        $byUrl = News::factory()->create([
            'news_source_id' => $source->getKey(),
            'normalized_source_url' => 'https://example.com/one',
            'normalized_title' => 'headline one',
        ]);

        $this->assertTrue($this->service->findExistingNews('https://example.com/one')->is($byUrl));

        $this->assertTrue($this->service->findExistingNews(
            'https://www.example.com/one/',
            'HEADLINE ONE',
            $source->getKey(),
        )->is($byUrl));

        $this->assertNull($this->service->findExistingNews(null, null, null));
    }

    public function test_the_current_record_is_excluded_from_detection_on_edit(): void
    {
        $source = NewsSource::factory()->create();

        $article = News::factory()->create([
            'news_source_id' => $source->getKey(),
            'normalized_title' => 'kept headline',
        ]);

        // Without the exception the article matches itself; with it,
        // the same record is invisible to the lookup (edit scenario).
        $this->assertNull(
            $this->service->findBySourceAndTitle('kept headline', $source->getKey(), $article->getKey()),
        );

        $this->assertNotNull(
            $this->service->findBySourceAndTitle('kept headline', $source->getKey()),
        );
    }

    public function test_partial_unique_index_prevents_url_duplicates_at_the_database_level(): void
    {
        News::factory()->create([
            'normalized_source_url' => 'https://example.com/dup',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        News::factory()->create([
            'normalized_source_url' => 'https://example.com/dup',
        ]);
    }

    public function test_partial_unique_index_ignores_null_urls(): void
    {
        News::factory()->create([
            'normalized_source_url' => null,
        ]);

        $second = News::factory()->create([
            'normalized_source_url' => null,
        ]);

        $this->assertDatabaseCount('news', 2);
    }

    public function test_partial_unique_index_scopes_title_duplicates_per_source(): void
    {
        $source = NewsSource::factory()->create();

        News::factory()->create([
            'news_source_id' => $source->getKey(),
            'normalized_title' => 'headline',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        News::factory()->create([
            'news_source_id' => $source->getKey(),
            'normalized_title' => 'headline',
        ]);
    }
}
