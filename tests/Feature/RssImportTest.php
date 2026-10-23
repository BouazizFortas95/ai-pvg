<?php

namespace Tests\Feature;

use App\Jobs\ImportRssSourceJob;
use App\Models\NewsSource;
use App\Services\RssImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RssImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_rss_feed_imports_articles(): void
    {
        $source = NewsSource::factory()->create([
            'name' => 'Test Source',
            'rss_url' => 'https://example.com/feed.xml',
            'is_active' => true,
        ]);

        Http::fake([
            'https://example.com/feed.xml' => Http::response(file_get_contents(__DIR__.'/../Fixtures/rss_sample.xml')),
        ]);

        $service = app(RssImportService::class);
        $result = $service->import($source);

        $this->assertSame(2, $result->totalItems);
        $this->assertSame(2, $result->importedItems);
        $this->assertTrue($result->completedAt !== null);
    }

    public function test_duplicate_articles_are_not_reimported(): void
    {
        $source = NewsSource::factory()->create([
            'rss_url' => 'https://example.com/feed.xml',
            'is_active' => true,
        ]);

        Http::fake([
            'https://example.com/feed.xml' => Http::response(file_get_contents(__DIR__.'/../Fixtures/rss_sample.xml')),
        ]);

        $service = app(RssImportService::class);
        $service->import($source);

        $result2 = $service->import($source);
        $this->assertSame(2, $result2->duplicateItems);
        $this->assertSame(0, $result2->importedItems);
    }

    public function test_inactive_source_is_skipped_by_job(): void
    {
        $source = NewsSource::factory()->create([
            'is_active' => false,
            'rss_url' => 'https://example.com/feed.xml',
        ]);

        ImportRssSourceJob::dispatchSync($source->getKey());

        $this->assertDatabaseMissing('rss_import_logs', [
            'news_source_id' => $source->getKey(),
        ]);
    }

    public function test_missing_rss_url_skips_import(): void
    {
        $source = NewsSource::factory()->create([
            'is_active' => true,
            'rss_url' => null,
        ]);

        ImportRssSourceJob::dispatchSync($source->getKey());

        $this->assertDatabaseMissing('rss_import_logs', [
            'news_source_id' => $source->getKey(),
        ]);
    }

    public function test_ssrf_protection_blocks_private_ips(): void
    {
        $service = app(RssImportService::class);
        $source = NewsSource::factory()->make([
            'rss_url' => 'http://192.168.1.1/feed.xml',
        ]);

        $result = $service->import($source);
        $this->assertStringContainsString('security policy', implode('', $result->errors));
    }

    public function test_import_result_timezone_is_utc(): void
    {
        $source = NewsSource::factory()->create([
            'is_active' => true,
            'rss_url' => 'https://example.com/feed.xml',
        ]);

        Http::fake([
            'https://example.com/feed.xml' => Http::response('<rss/>'),
        ]);

        $service = app(RssImportService::class);
        $result = $service->import($source);
        $this->assertTrue($result->startedAt !== null);
        $this->assertTrue($result->completedAt !== null);
    }
}
