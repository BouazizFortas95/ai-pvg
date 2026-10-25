<?php

namespace Tests\Feature;

use App\Enums\NewsStatus;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsSource;
use App\Services\RssImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveTechNewsImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);

            return Http::response($this->feedXml($host));
        });
    }

    public function test_import_options_bound_results_and_apply_category_and_status(): void
    {
        $category = NewsCategory::factory()->create(['slug' => 'technology']);
        $source = NewsSource::factory()->create([
            'name' => 'Option Test Feed',
            'rss_url' => 'https://options.example.test/feed.xml',
            'is_active' => true,
        ]);

        $result = app(RssImportService::class)->import($source, [
            'limit' => 2,
            'status' => NewsStatus::Ready->value,
            'news_category_id' => (int) $category->getKey(),
            'category' => 'technology',
        ]);

        $this->assertSame(8, $result->totalItems);
        $this->assertSame(2, $result->importedItems);

        $news = News::query()->where('news_source_id', $source->getKey())->orderBy('id')->get();

        $this->assertCount(2, $news);
        $this->assertTrue($news->every(fn (News $article): bool => $article->status === NewsStatus::Ready));
        $this->assertTrue($news->every(fn (News $article): bool => $article->news_category_id === $category->getKey()));
        $this->assertTrue($news->every(fn (News $article): bool => $article->category === 'technology'));
        $this->assertTrue($news->every(fn (News $article): bool => filled($article->source_url)));
        $this->assertTrue($news->every(fn (News $article): bool => $article->published_at !== null));
    }

    public function test_live_tech_command_imports_ten_ready_articles_and_is_idempotent(): void
    {
        $this->artisan('news:import-live-tech', ['--limit' => 10, '--per-feed' => 3])
            ->assertSuccessful();

        $category = NewsCategory::query()->where('slug', 'technology')->firstOrFail();
        $news = News::query()->orderBy('id')->get();

        $this->assertCount(10, $news);
        $this->assertTrue($news->every(fn (News $article): bool => $article->status === NewsStatus::Ready));
        $this->assertTrue($news->every(fn (News $article): bool => $article->news_category_id === $category->getKey()));
        $this->assertTrue($news->every(fn (News $article): bool => $article->category === 'technology'));
        $this->assertTrue($news->every(fn (News $article): bool => $article->news_source_id !== null));
        $this->assertTrue($news->every(fn (News $article): bool => filled($article->source_url)));
        $this->assertTrue($news->every(fn (News $article): bool => $article->published_at !== null));
        $this->assertTrue($news->every(fn (News $article): bool => filled($article->summary) || filled($article->content)));

        foreach (['techcrunch', 'the-verge', 'ars-technica', 'github-blog', 'hacker-news'] as $slug) {
            $this->assertDatabaseHas('news_sources', ['slug' => $slug, 'is_active' => false]);
        }

        // The first three feeds fill the per-feed cap; the fourth supplies the
        // final article and Hacker News is never requested.
        Http::assertSentCount(4);

        $this->artisan('news:import-live-tech', ['--limit' => 10, '--per-feed' => 3])
            ->assertSuccessful();

        $this->assertSame(10, News::query()->count());
        Http::assertSentCount(4);
    }

    private function feedXml(string $host): string
    {
        $items = '';

        for ($index = 1; $index <= 8; $index++) {
            $title = "Live import {$host} article {$index}";
            $link = "https://{$host}/2026/10/article-{$index}";
            $published = now()->subMinutes($index)->toRssString();

            $items .= "<item><title>{$title}</title><link>{$link}</link>"
                ."<description>Grounded summary for {$title}.</description>"
                ."<pubDate>{$published}</pubDate></item>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"><channel><title>Test feed</title>'.$items.'</channel></rss>';
    }
}
