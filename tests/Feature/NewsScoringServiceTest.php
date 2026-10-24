<?php

namespace Tests\Feature;

use App\Exceptions\Automation\TriageDisabledException;
use App\Models\FeatureFlag;
use App\Models\News;
use App\Models\NewsSource;
use App\Services\Automation\Scoring\NewsScoringService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsTriageFixtures;
use Tests\TestCase;

class NewsScoringServiceTest extends TestCase
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
            'automation.news_scoring.auto_greenlight_min' => 85,
            'automation.news_scoring.review_band_min' => 60,
            'automation.news_scoring.velocity_window_hours' => 12,
            'automation.news_scoring.velocity_target_sources' => 5,
            'automation.news_scoring.coverage_similarity_threshold' => 0.35,
            'automation.news_scoring.novelty_window_days' => 7,
            'automation.news_scoring.recency_half_life_hours' => 24,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scoring_is_reproducible_and_stores_explainable_components(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-24 12:00:00');
        $target = $this->makeNewsWithSource(
            'OpenAI launches breakthrough AI model',
            NewsSource::factory()->create(),
            ['published_at' => $asOf->subHour()],
        );
        $this->makeNewsWithSource(
            'OpenAI launches breakthrough AI model',
            NewsSource::factory()->create(),
            ['published_at' => $asOf->subHours(2)],
        );
        $this->makeNewsWithSource(
            'OpenAI launches breakthrough AI model',
            NewsSource::factory()->create(),
            ['published_at' => $asOf->subHours(3)],
        );
        $this->makeNewsWithSource(
            'Local sports team wins regional final',
            NewsSource::factory()->create(),
            ['published_at' => $asOf->subHours(2)],
        );

        $service = app(NewsScoringService::class);
        $first = $service->score($target, $asOf);
        $second = $service->score($target, $asOf);

        $this->assertSame(4, News::query()->count());
        $this->assertSame('OpenAI launches breakthrough AI model', $target->fresh()?->title);
        $this->assertSame($first->composite_score, $second->composite_score);
        $this->assertSame($first->breakdown, $second->breakdown);
        $this->assertDatabaseCount('news_scores', 1);

        $this->assertGreaterThan(0, $first->velocity_score);
        $this->assertGreaterThan(0, $first->keyword_heat_score);
        $this->assertGreaterThanOrEqual(0, $first->novelty_score);
        $this->assertGreaterThan(0, $first->source_diversity_score);
        $this->assertGreaterThan(0, $first->recency_score);

        $breakdown = $first->breakdown;

        $this->assertSame('g02-news-score-v1', $breakdown['version']);
        $this->assertSame(
            ['velocity', 'keyword_heat', 'novelty', 'source_diversity', 'recency'],
            array_keys($breakdown['component_scores']),
        );
        $this->assertSame(
            ['velocity', 'keyword_heat', 'novelty', 'source_diversity', 'recency'],
            array_keys($breakdown['components']),
        );
        $this->assertSame($first->composite_score, $breakdown['composite_score']);
        $this->assertSame($first->band?->value, $breakdown['band']);
        $this->assertSame(3, $breakdown['components']['velocity']['evidence']['distinct_sources']);
        $this->assertNotEmpty($breakdown['explanations']);
        $this->assertSame('2026-10-24T12:00:00+00:00', $breakdown['as_of']);

        Http::assertNothingSent();
    }

    public function test_triage_database_flag_overrides_the_enabled_config_default(): void
    {
        $asOf = CarbonImmutable::parse('2026-10-24 12:00:00');
        $news = $this->makeNewsWithSource(
            'Triage flag test article',
            NewsSource::factory()->create(),
            ['published_at' => $asOf],
        );

        FeatureFlag::setFlag('triage.news_scoring_enabled', false);

        try {
            app(NewsScoringService::class)->score($news, $asOf);
            $this->fail('Scoring should fail closed when the triage feature flag is disabled.');
        } catch (TriageDisabledException) {
            // Expected: no score row is written.
        }

        $this->assertDatabaseCount('news_scores', 0);
        Http::assertNothingSent();
    }
}
