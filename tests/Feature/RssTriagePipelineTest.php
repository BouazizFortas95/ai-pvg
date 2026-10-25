<?php

namespace Tests\Feature;

use App\Enums\NewsStatus;
use App\Jobs\ImportRssSourceJob;
use App\Jobs\RunTriageJob;
use App\Models\AutomationRun;
use App\Models\News;
use App\Models\NewsSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RssTriagePipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));
        config([
            'automation.enabled' => true,
            'automation.triage.enabled' => true,
            'automation.triage.after_import' => true,
            'automation.triage.import_dispatch_delay_seconds' => 0,
            'automation.dry_run' => true,
            'automation.news_scoring.velocity_target_sources' => 3,
            'automation.story_clustering.similarity_threshold' => 0.35,
            'automation.story_clustering.window_hours' => 48,
            'automation.story_clustering.min_sources' => 3,
            'automation.story_clustering.max_sources' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_queued_rss_imports_become_ready_and_flow_through_scoring_clustering_and_greenlight(): void
    {
        $this->fakeSameStoryFeeds();

        foreach ($this->storyFeeds() as $host => $story) {
            $source = NewsSource::factory()->create([
                'name' => $story['name'],
                'slug' => $story['slug'],
                'rss_url' => 'https://'.$host.'/feed.xml',
                'is_active' => true,
            ]);

            ImportRssSourceJob::dispatchSync($source->getKey());
        }

        $news = News::query()->with('newsCategory')->orderBy('id')->get();

        $this->assertCount(3, $news);
        $this->assertTrue($news->every(
            fn (News $article): bool => $article->status === NewsStatus::Ready,
        ));
        $this->assertEqualsCanonicalizing(
            ['Artificial Intelligence', 'Security', 'Startups'],
            $news->pluck('newsCategory.name')->all(),
        );

        $this->assertDatabaseCount('news_scores', 3);
        $this->assertDatabaseCount('story_clusters', 1);
        $this->assertDatabaseCount('story_cluster_news', 3);

        $cluster = News::query()->firstOrFail()->storyClusters()->firstOrFail();
        $this->assertSame(3, (int) $cluster->source_count);
        $this->assertSame(3, $cluster->news()->count());
        $this->assertCount(
            3,
            $cluster->news()->with('newsCategory')->get()->pluck('newsCategory.name')->unique(),
        );

        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertDatabaseCount('video_scripts', 0);
        $this->assertDatabaseCount('approval_gates', 0);

        $run = AutomationRun::query()->firstOrFail();
        $this->assertTrue((bool) $run->dry_run);
        $this->assertNotNull(data_get($run->meta, 'planned_script'));

        Http::assertSentCount(3);
    }

    public function test_import_queues_the_gated_triage_handshake_when_items_are_imported(): void
    {
        Queue::fake();
        $this->fakeSameStoryFeeds();

        $source = NewsSource::factory()->create([
            'name' => 'Alpha Wire',
            'slug' => 'alpha-wire',
            'rss_url' => 'https://alpha.example.test/feed.xml',
            'is_active' => true,
        ]);

        // Queue::fake replaces the sync connection, so run the import handler
        // directly while still observing the nested triage dispatch.
        app()->call([new ImportRssSourceJob($source->getKey()), 'handle']);

        $this->assertSame(1, News::query()->where('status', NewsStatus::Ready->value)->count());
        $this->assertDatabaseCount('news_scores', 0);
        Queue::assertPushed(RunTriageJob::class, 1);
    }

    public function test_import_does_not_queue_triage_when_the_gate_is_disabled(): void
    {
        Queue::fake();
        config(['automation.triage.enabled' => false]);
        $this->fakeSameStoryFeeds();

        $source = NewsSource::factory()->create([
            'name' => 'Alpha Wire',
            'slug' => 'alpha-wire',
            'rss_url' => 'https://alpha.example.test/feed.xml',
            'is_active' => true,
        ]);

        app()->call([new ImportRssSourceJob($source->getKey()), 'handle']);

        $this->assertSame(1, News::query()->where('status', NewsStatus::Ready->value)->count());
        $this->assertDatabaseCount('news_scores', 0);
        Queue::assertNotPushed(RunTriageJob::class);
    }

    public function test_queued_triage_job_skips_cleanly_when_the_gate_is_disabled(): void
    {
        config(['automation.triage.enabled' => false]);

        News::factory()->create([
            'status' => NewsStatus::Ready->value,
            'published_at' => now(),
        ]);

        RunTriageJob::dispatchSync();

        $this->assertDatabaseCount('news_scores', 0);
        $this->assertDatabaseCount('story_clusters', 0);
        Http::assertNothingSent();
    }

    private function fakeSameStoryFeeds(): void
    {
        $feeds = $this->storyFeeds();

        Http::fake(function (Request $request) use ($feeds) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $story = $feeds[$host] ?? null;

            if ($story === null) {
                return Http::response('Not found.', 404);
            }

            return Http::response($this->feedXml(
                $story['title'],
                'Breaking exclusive report: the launch is a security and breach crisis record for the AI model.',
                'https://'.$host.'/story',
                $story['category'],
            ));
        });
    }

    /**
     * @return array<string, array{name: string, slug: string, title: string, category: string}>
     */
    private function storyFeeds(): array
    {
        return [
            'alpha.example.test' => [
                'name' => 'Alpha Wire',
                'slug' => 'alpha-wire',
                'title' => 'OpenAI releases GPT-5 with improved reasoning',
                'category' => 'AI',
            ],
            'beta.example.test' => [
                'name' => 'Beta Daily',
                'slug' => 'beta-daily',
                'title' => 'OpenAI’s GPT-5 is here and it is better at reasoning',
                'category' => 'Security',
            ],
            'gamma.example.test' => [
                'name' => 'Gamma Review',
                'slug' => 'gamma-review',
                'title' => 'GPT-5 launch: OpenAI says its new model improves reasoning',
                'category' => 'Startups',
            ],
        ];
    }

    private function feedXml(string $title, string $summary, string $url, string $category): string
    {
        $title = htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $summary = htmlspecialchars($summary, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $category = htmlspecialchars($category, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $published = now()->subMinutes(3)->toRssString();

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0"><channel><title>Test feed</title><item>'
            ."<title>{$title}</title><link>{$url}</link><category>{$category}</category>"
            ."<description>{$summary}</description>"
            ."<pubDate>{$published}</pubDate></item></channel></rss>";
    }
}
