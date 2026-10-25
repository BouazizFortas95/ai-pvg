<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsSource;
use App\Services\RssImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NewsCategoryDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_rss_categories_are_reused_created_and_detected_without_dropping_articles(): void
    {
        $existingAi = NewsCategory::factory()->create([
            'name' => 'AI',
            'slug' => 'ai',
        ]);

        $source = NewsSource::factory()->create([
            'name' => 'Category Feed',
            'rss_url' => 'https://categories.example.test/feed.xml',
            'is_active' => true,
        ]);

        $items = [
            $this->item(
                'AI funding surge continues',
                'https://categories.example.test/ai',
                '<category>AI</category>',
                'Investors remain focused on model builders.',
            ),
            $this->item(
                'Quantum Computing milestone reported',
                'https://categories.example.test/quantum',
                '<category>Quantum Computing</category>',
                'Researchers describe a new qubit design.',
            ),
            $this->item(
                'Ransomware attack exploits zero-day flaw',
                'https://categories.example.test/security',
                '',
                'The data breach forced password resets across affected networks.',
            ),
            $this->item(
                'A short product note',
                'https://categories.example.test/general',
                '',
                'A brief operational update with no topic keywords.',
            ),
        ];

        Http::fake([
            $source->rss_url => Http::response($this->feed($items)),
        ]);

        $result = app(RssImportService::class)->import($source);

        $this->assertSame(4, $result->totalItems);
        $this->assertSame(4, $result->importedItems);
        $this->assertSame(0, $result->skippedItems);
        $this->assertSame(0, $result->failedItems);

        $news = News::query()->with('newsCategory')->orderBy('id')->get();

        $this->assertSame($existingAi->getKey(), $news[0]->news_category_id);
        $this->assertSame('AI', $news[0]->newsCategory->name);
        $this->assertSame('Quantum Computing', $news[1]->newsCategory->name);
        $this->assertSame('Security', $news[2]->newsCategory->name);
        $this->assertSame('Uncategorized', $news[3]->newsCategory->name);

        $this->assertSame('ai', $news[0]->category);
        $this->assertSame('quantum-computing', $news[1]->category);
        $this->assertSame('security', $news[2]->category);
        $this->assertSame('uncategorized', $news[3]->category);

        $this->assertDatabaseCount('news_categories', 4);
        $this->assertDatabaseHas('news_categories', ['name' => 'Quantum Computing', 'slug' => 'quantum-computing']);
        $this->assertDatabaseHas('news_categories', ['name' => 'Security']);
        $this->assertDatabaseHas('news_categories', ['name' => 'Uncategorized']);
        Http::assertSentCount(1);
    }

    public function test_feed_level_category_is_used_when_article_has_no_category_or_detectable_content(): void
    {
        $existingSecurity = NewsCategory::factory()->create([
            'name' => 'Security',
            'slug' => 'security',
        ]);

        $source = NewsSource::factory()->create([
            'name' => 'Feed Category Source',
            'rss_url' => 'https://feed-category.example.test/rss.xml',
            'is_active' => true,
        ]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"><channel><title>Test</title>'
            .'<category>cybersecurity</category>'
            .'<item><title>Weekly roundup</title><link>https://feed-category.example.test/1</link>'
            .'<description>A short operational note.</description></item>'
            .'</channel></rss>';

        Http::fake([$source->rss_url => Http::response($xml)]);

        $categoryCount = NewsCategory::query()->count();

        $result = app(RssImportService::class)->import($source);
        $news = News::query()->with('newsCategory')->firstOrFail();

        $this->assertSame(1, $result->importedItems);
        $this->assertSame($existingSecurity->getKey(), $news->news_category_id);
        $this->assertSame('Security', $news->newsCategory->name);
        $this->assertSame($categoryCount, NewsCategory::query()->count());
        Http::assertSentCount(1);
    }

    public function test_specific_article_category_wins_over_a_generic_tag(): void
    {
        $source = NewsSource::factory()->create([
            'name' => 'Specific Tag Source',
            'rss_url' => 'https://specific-tag.example.test/feed.xml',
            'is_active' => true,
        ]);

        $item = $this->item(
            'Acme raises a seed round',
            'https://specific-tag.example.test/startup',
            '<category>Technology</category><category>Startups</category>',
            'The new company plans to hire engineers.',
        );

        Http::fake([$source->rss_url => Http::response($this->feed([$item]))]);

        $result = app(RssImportService::class)->import($source);
        $news = News::query()->with('newsCategory')->firstOrFail();

        $this->assertSame(1, $result->importedItems);
        $this->assertSame('Startups', $news->newsCategory->name);
        $this->assertDatabaseHas('news_categories', ['name' => 'Startups', 'slug' => 'startups']);
        $this->assertDatabaseMissing('news_categories', ['name' => 'Technology']);
        Http::assertSentCount(1);
    }

    private function item(string $title, string $url, string $categories, string $summary): string
    {
        $title = htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $summary = htmlspecialchars($summary, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return "<item><title>{$title}</title><link>{$url}</link>"
            .$categories
            ."<description>{$summary}</description><pubDate>"
            .now()->subMinute()->toRssString()
            .'</pubDate></item>';
    }

    /**
     * @param  list<string>  $items
     */
    private function feed(array $items): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"><channel><title>Category test</title>'
            .implode('', $items)
            .'</channel></rss>';
    }
}
