<?php

namespace Tests\Feature;

use App\Enums\AutomationStageStatus;
use App\Models\NewsSource;
use App\Services\Automation\AutomationOrchestrator;
use App\Services\Automation\Stages\AutoGreenlightStage;
use App\Services\Automation\Stages\ClusterStoriesStage;
use App\Services\Automation\Stages\ScoreNewsStage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsTriageFixtures;
use Tests\TestCase;

class TriageStagesTest extends TestCase
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
            'automation.dry_run' => true,
            'automation.story_clustering.similarity_threshold' => 0.5,
            'automation.story_clustering.window_hours' => 48,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_score_and_cluster_stages_write_their_orchestrator_ledgers(): void
    {
        $news = [];

        for ($i = 0; $i < 3; $i++) {
            $news[] = $this->makeNewsWithSource(
                'A shared AI story across sources',
                NewsSource::factory()->create(),
                ['published_at' => now()->subMinutes($i + 1)],
            );
        }

        $orchestrator = app(AutomationOrchestrator::class);
        $run = $orchestrator->createRun('triage.test', $news[0]);

        $scoreResult = $orchestrator->executeStage($run, 'score_news', app(ScoreNewsStage::class));
        $clusterResult = $orchestrator->executeStage($run, 'cluster_stories', app(ClusterStoriesStage::class));

        $this->assertTrue($scoreResult->successful());
        $this->assertSame($news[0]->getKey(), $scoreResult->output['news_id']);
        $this->assertArrayHasKey('composite_score', $scoreResult->output);
        $this->assertTrue($clusterResult->successful());
        $this->assertSame(1, $clusterResult->output['cluster_count']);
        $this->assertSame(3, $clusterResult->output['clusters'][0]['source_count']);

        $this->assertSame(2, $run->stageRuns()->count());
        $this->assertDatabaseCount('news_scores', 1);
        $this->assertDatabaseCount('story_clusters', 1);
        $this->assertDatabaseCount('story_cluster_news', 3);
        Http::assertNothingSent();
    }

    public function test_auto_greenlight_stage_uses_cluster_subject_and_dry_run_plan(): void
    {
        $news = [];

        for ($i = 0; $i < 3; $i++) {
            $news[] = $this->makeNewsWithSource(
                'A cluster ready for greenlight',
                NewsSource::factory()->create(),
                ['published_at' => now()->subMinutes($i + 1)],
            );
        }

        $cluster = $this->makeStoryCluster($news);
        $this->makeNewsScore($news[0], 95);
        $cluster = $cluster->fresh(['news.newsScore']);

        $orchestrator = app(AutomationOrchestrator::class);
        $run = $orchestrator->createRun('triage.greenlight.test', $cluster);
        $result = $orchestrator->executeStage($run, 'auto_greenlight', app(AutoGreenlightStage::class));

        $this->assertTrue($result->successful());
        $this->assertSame('auto_greenlight', $result->output['action']);
        $this->assertSame('auto_greenlight', $result->output['band']);
        $this->assertTrue($result->output['dry_run']);
        $this->assertDatabaseCount('video_scripts', 0);
        Http::assertNothingSent();
    }

    public function test_stages_skip_cleanly_when_triage_is_disabled(): void
    {
        config(['automation.triage.enabled' => false]);
        $news = $this->makeNewsWithSource('Disabled triage story', NewsSource::factory()->create());
        $orchestrator = app(AutomationOrchestrator::class);
        $run = $orchestrator->createRun('triage.disabled.test', $news);

        $result = $orchestrator->executeStage($run, 'score_news', app(ScoreNewsStage::class));

        $this->assertSame(AutomationStageStatus::Skipped, $result->status);
        $this->assertSame('triage_disabled', $result->output['reason']);
        $this->assertDatabaseCount('news_scores', 0);
        Http::assertNothingSent();
    }
}
