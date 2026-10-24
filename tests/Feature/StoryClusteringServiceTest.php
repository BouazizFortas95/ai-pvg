<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\NewsSource;
use App\Models\StoryCluster;
use App\Services\Automation\Clustering\StoryClusteringService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsTriageFixtures;
use Tests\TestCase;

class StoryClusteringServiceTest extends TestCase
{
    use BuildsTriageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));
        config([
            'automation.triage.enabled' => true,
            'automation.story_clustering.window_hours' => 48,
            'automation.story_clustering.similarity_threshold' => 0.5,
            'automation.story_clustering.min_sources' => 3,
            'automation.story_clustering.max_sources' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_clusters_have_grounded_fact_sheets_and_reruns_are_idempotent(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-24 12:00:00');
        $news = [];

        for ($i = 0; $i < 4; $i++) {
            $news[] = $this->makeNewsWithSource(
                'OpenAI launches breakthrough AI model',
                NewsSource::factory()->create(),
                ['published_at' => $asOf->subMinutes(10 + $i)],
            );
        }

        $service = app(StoryClusteringService::class);
        $first = $service->cluster($asOf);
        $second = $service->cluster($asOf);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame($first[0]->getKey(), $second[0]->getKey());
        $this->assertSame(4, $first[0]->source_count);
        $this->assertCount(4, $first[0]->news);
        $this->assertDatabaseCount('story_clusters', 1);
        $this->assertDatabaseCount('story_cluster_news', 4);

        $validIds = $first[0]->newsIds();
        $this->assertTrue($first[0]->news->contains(fn (News $article): bool => (bool) $article->pivot->is_primary));

        foreach (['claims', 'bullets', 'entities', 'timeline'] as $section) {
            foreach ($first[0]->fact_sheet[$section] ?? [] as $item) {
                $this->assertArrayHasKey('source_news_ids', $item);
                $this->assertNotEmpty($item['source_news_ids']);
                $this->assertSame([], array_diff($item['source_news_ids'], $validIds));
            }
        }

        Http::assertNothingSent();
    }

    public function test_clusters_are_partitioned_between_three_and_five_sources(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-24 12:00:00');
        $news = [];

        for ($i = 0; $i < 7; $i++) {
            $news[] = $this->makeNewsWithSource(
                'Major AI policy announcement changes the industry',
                NewsSource::factory()->create(),
                ['published_at' => $asOf->subMinutes(5 + $i)],
            );
        }

        $clusters = app(StoryClusteringService::class)->cluster($asOf);

        $this->assertCount(2, $clusters);
        $this->assertSame(7, collect($clusters)->sum(fn (StoryCluster $cluster): int => $cluster->news->count()));

        foreach ($clusters as $cluster) {
            $this->assertGreaterThanOrEqual(3, $cluster->source_count);
            $this->assertLessThanOrEqual(5, $cluster->source_count);
            $this->assertGreaterThanOrEqual(3, $cluster->news->count());
            $this->assertLessThanOrEqual(5, $cluster->news->count());
        }

        Http::assertNothingSent();
    }

    public function test_sanitize_fact_sheet_strips_ungrounded_or_invalid_claims(): void
    {
        $service = app(StoryClusteringService::class);

        $clean = $service->sanitizeFactSheet([
            'claims' => [
                ['claim' => 'Grounded claim', 'source_news_ids' => [1, 999]],
                ['claim' => 'Missing sources'],
                ['claim' => 'Invalid source', 'source_news_ids' => [999]],
                ['claim' => 'Wrong type', 'source_news_ids' => '1'],
            ],
            'entities' => [
                ['name' => 'Grounded entity', 'source_news_ids' => [2]],
                ['name' => 'Ungrounded entity'],
            ],
            'timeline' => [
                ['published_at' => '2026-10-24T12:00:00Z', 'source_news_ids' => [1, 2]],
            ],
            'open_questions' => [
                ['question' => 'Ungrounded question'],
            ],
            'bullets' => [
                ['text' => 'Grounded bullet', 'source_news_ids' => [2]],
            ],
        ], [1, 2]);

        $this->assertCount(1, $clean['claims']);
        $this->assertSame([1], $clean['claims'][0]['source_news_ids']);
        $this->assertCount(1, $clean['entities']);
        $this->assertCount(1, $clean['timeline']);
        $this->assertCount(0, $clean['open_questions']);
        $this->assertCount(1, $clean['bullets']);
        Http::assertNothingSent();
    }
}
