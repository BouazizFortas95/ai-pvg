<?php

namespace Tests\Feature;

use App\Enums\NewsStatus;
use App\Models\News;
use App\Models\NewsSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RssImportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_rss_import_fetches_all_available_articles_from_all_active_sources(): void
    {
        $this->fakeFeeds();

        $alpha = NewsSource::factory()->create([
            'name' => 'Alpha Broad Feed',
            'rss_url' => 'https://alpha.example.test/feed.xml',
            'is_active' => true,
        ]);
        $beta = NewsSource::factory()->create([
            'name' => 'Beta Broad Feed',
            'rss_url' => 'https://beta.example.test/feed.xml',
            'is_active' => true,
        ]);
        NewsSource::factory()->create([
            'name' => 'Inactive Broad Feed',
            'rss_url' => 'https://inactive.example.test/feed.xml',
            'is_active' => false,
        ]);
        NewsSource::factory()->create([
            'name' => 'No URL Feed',
            'rss_url' => null,
            'is_active' => true,
        ]);

        $this->artisan('rss:import')
            ->expectsOutputToContain('RSS import: all active sources, no category filter')
            ->expectsOutputToContain('Imported 16 article(s) from 2 source(s).')
            ->assertSuccessful();

        $this->assertSame(8, News::query()->where('news_source_id', $alpha->getKey())->count());
        $this->assertSame(8, News::query()->where('news_source_id', $beta->getKey())->count());
        $this->assertSame(16, News::query()->count());
        $this->assertSame(16, News::query()->where('status', NewsStatus::Ready->value)->count());
        $this->assertSame(16, News::query()->whereNotNull('news_category_id')->count());

        $this->assertDatabaseHas('news_categories', ['name' => 'Artificial Intelligence']);
        $this->assertDatabaseHas('news_categories', ['name' => 'Security']);
        Http::assertSentCount(2);

        $this->artisan('rss:import')
            ->expectsOutputToContain('Imported 0 article(s) from 2 source(s).')
            ->assertSuccessful();

        $this->assertSame(16, News::query()->count());
        Http::assertSentCount(4);
    }

    public function test_rss_import_optional_caps_remain_available_for_operators(): void
    {
        $this->fakeFeeds();

        NewsSource::factory()->create([
            'name' => 'Alpha Capped Feed',
            'rss_url' => 'https://alpha.example.test/feed.xml',
            'is_active' => true,
        ]);
        NewsSource::factory()->create([
            'name' => 'Beta Capped Feed',
            'rss_url' => 'https://beta.example.test/feed.xml',
            'is_active' => true,
        ]);

        $exitCode = Artisan::call('rss:import', [
            '--limit' => 3,
            '--per-feed' => 2,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(3, News::query()->count());
        $this->assertSame(3, News::query()->where('status', NewsStatus::Ready->value)->count());
        Http::assertSentCount(2);
    }

    private function fakeFeeds(): void
    {
        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $items = '';

            for ($index = 1; $index <= 8; $index++) {
                $category = $index % 2 === 0 ? 'AI' : 'Security';
                $title = "Broad feed {$host} story {$index}";
                $summary = 'A short operational summary for the imported item.';

                $items .= '<item><title>'.htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</title>'
                    .'<link>https://'.$host.'/story/'.$index.'</link>'
                    .'<category>'.$category.'</category>'
                    .'<description>'.htmlspecialchars($summary, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</description>'
                    .'<pubDate>'.now()->subMinutes($index)->toRssString().'</pubDate></item>';
            }

            return Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<rss version="2.0"><channel><title>Broad test feed</title>'.$items.'</channel></rss>'
            );
        });
    }
}
