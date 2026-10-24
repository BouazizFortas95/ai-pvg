<?php

namespace Tests\Feature;

use App\Enums\ApprovalGateType;
use App\Enums\AutomationRunStatus;
use App\Enums\TriageBand;
use App\Enums\VideoScriptStatus;
use App\Exceptions\Automation\TriageDisabledException;
use App\Models\FeatureFlag;
use App\Models\News;
use App\Models\NewsSource;
use App\Models\StoryCluster;
use App\Services\Automation\Greenlight\AutoGreenlightService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsTriageFixtures;
use Tests\TestCase;

class AutoGreenlightServiceTest extends TestCase
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
            'automation.dry_run' => false,
            'automation.news_scoring.auto_greenlight_min' => 85,
            'automation.news_scoring.review_band_min' => 60,
            'automation.triage.target_duration' => 45,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_auto_band_creates_one_grounded_draft_script_and_never_duplicates_it(): void
    {
        [$cluster, $news] = $this->clusterWithScore(90.0);
        $service = app(AutoGreenlightService::class);

        $result = $service->evaluateCluster($cluster);

        $this->assertSame(TriageBand::AutoGreenlight, $result->band);
        $this->assertSame('auto_greenlight', $result->action);
        $this->assertNotNull($result->run);
        $this->assertNotNull($result->script);
        $this->assertSame(AutomationRunStatus::Completed, $result->run->status);
        $this->assertSame(VideoScriptStatus::Draft, $result->script->status);
        $this->assertSame($news[0]->getKey(), $result->script->news_id);
        $this->assertCount(5, $result->script->scenes);
        $this->assertSame(45, array_sum(array_column($result->script->scenes, 'duration')));

        foreach ($result->script->scenes as $scene) {
            $this->assertArrayHasKey('source_news_ids', $scene);
            $this->assertNotEmpty($scene['source_news_ids']);
        }

        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertDatabaseCount('video_scripts', 1);
        $this->assertDatabaseCount('approval_gates', 0);
        $this->assertDatabaseCount('ai_usage_ledger', 0);

        $again = $service->evaluateCluster($cluster->fresh(['news.newsScore']));

        $this->assertSame($result->run?->getKey(), $again->run?->getKey());
        $this->assertSame($result->script?->getKey(), $again->script?->getKey());
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertDatabaseCount('video_scripts', 1);
        Http::assertNothingSent();
    }

    public function test_review_band_opens_an_idempotent_approval_gate_without_a_script(): void
    {
        [$cluster] = $this->clusterWithScore(70.0);
        $service = app(AutoGreenlightService::class);

        $result = $service->evaluateCluster($cluster);

        $this->assertSame(TriageBand::Review, $result->band);
        $this->assertSame('review_gate', $result->action);
        $this->assertNotNull($result->gate);
        $this->assertSame(ApprovalGateType::AutoGreenlight, $result->gate->gate_type);
        $this->assertNull($result->script);
        $this->assertDatabaseCount('approval_gates', 1);
        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertDatabaseCount('video_scripts', 0);

        $again = $service->evaluateCluster($cluster->fresh(['news.newsScore']));

        $this->assertSame($result->gate?->getKey(), $again->gate?->getKey());
        $this->assertDatabaseCount('approval_gates', 1);
        Http::assertNothingSent();
    }

    public function test_below_review_band_takes_no_downstream_action(): void
    {
        [$cluster] = $this->clusterWithScore(40.0);

        $result = app(AutoGreenlightService::class)->evaluateCluster($cluster);

        $this->assertSame(TriageBand::BelowThreshold, $result->band);
        $this->assertSame('none', $result->action);
        $this->assertNull($result->run);
        $this->assertNull($result->script);
        $this->assertNull($result->gate);
        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertDatabaseCount('video_scripts', 0);
        $this->assertDatabaseCount('approval_gates', 0);
        Http::assertNothingSent();
    }

    public function test_dry_run_records_planned_script_without_creating_production_scripts(): void
    {
        config(['automation.dry_run' => true]);
        [$cluster] = $this->clusterWithScore(95.0);
        $service = app(AutoGreenlightService::class);

        $result = $service->evaluateCluster($cluster);

        $this->assertTrue($result->isPlanned());
        $this->assertNull($result->script);
        $this->assertIsArray($result->plannedScript);
        $this->assertSame(45, $result->plannedScript['estimated_duration']);
        $this->assertTrue($result->run?->dry_run);
        $this->assertNotNull(data_get($result->run?->meta, 'planned_script'));
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertDatabaseCount('video_scripts', 0);

        $again = $service->evaluateCluster($cluster->fresh(['news.newsScore']));

        $this->assertSame($result->run?->getKey(), $again->run?->getKey());
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertDatabaseCount('video_scripts', 0);
        Http::assertNothingSent();
    }

    public function test_feature_flag_off_blocks_greenlight_actions(): void
    {
        [$cluster] = $this->clusterWithScore(95.0);
        FeatureFlag::setFlag('triage.news_scoring_enabled', false);

        try {
            app(AutoGreenlightService::class)->evaluateCluster($cluster);
            $this->fail('Greenlight should fail closed when triage is disabled.');
        } catch (TriageDisabledException) {
            // Expected.
        }

        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertDatabaseCount('video_scripts', 0);
        $this->assertDatabaseCount('approval_gates', 0);
        Http::assertNothingSent();
    }

    public function test_threshold_guardrails_keep_auto_greenlight_at_85_or_above(): void
    {
        config([
            'automation.news_scoring.auto_greenlight_min' => 50,
            'automation.news_scoring.review_band_min' => 20,
        ]);

        [$cluster] = $this->clusterWithScore(70.0);

        $result = app(AutoGreenlightService::class)->evaluateCluster($cluster);

        $this->assertSame(TriageBand::Review, $result->band);
        $this->assertSame('review_gate', $result->action);
        $this->assertDatabaseCount('video_scripts', 0);
        $this->assertDatabaseCount('approval_gates', 1);
        Http::assertNothingSent();
    }

    /**
     * @return array{0: StoryCluster, 1: array<int, News>}
     */
    private function clusterWithScore(float $composite): array
    {
        $news = [];

        for ($i = 0; $i < 3; $i++) {
            $news[] = $this->makeNewsWithSource(
                'Shared AI policy story number '.$i,
                NewsSource::factory()->create(),
                ['published_at' => now()->subMinutes($i + 1)],
            );
        }

        $cluster = $this->makeStoryCluster($news);
        $this->makeNewsScore($news[0], $composite);

        return [$cluster->fresh(['news.newsScore']), $news];
    }
}
