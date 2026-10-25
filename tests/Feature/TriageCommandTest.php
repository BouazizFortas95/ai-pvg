<?php

namespace Tests\Feature;

use App\Enums\NewsStatus;
use App\Models\AutomationRun;
use App\Models\FeatureFlag;
use App\Models\News;
use App\Models\NewsScore;
use App\Models\NewsSource;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsTriageFixtures;
use Tests\TestCase;

class TriageCommandTest extends TestCase
{
    use BuildsTriageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));
        config([
            'automation.enabled' => true,
            'automation.triage.enabled' => true,
            'automation.dry_run' => false,
            'automation.story_clustering.similarity_threshold' => 0.5,
            'automation.story_clustering.window_hours' => 48,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_default_run_scores_only_ready_news_without_scores(): void
    {
        $source = NewsSource::factory()->create();

        $ready = $this->makeNewsWithSource('Ready AI launch story', $source, [
            'status' => NewsStatus::Ready->value,
        ]);
        $secondReady = $this->makeNewsWithSource('Second ready security story', $source, [
            'status' => NewsStatus::Ready->value,
        ]);
        $draft = $this->makeNewsWithSource('Draft story', $source, [
            'status' => NewsStatus::Draft->value,
        ]);
        $alreadyScored = $this->makeNewsWithSource('Already scored story', $source, [
            'status' => NewsStatus::Ready->value,
        ]);
        $this->makeNewsScore($alreadyScored, 50.0);

        $exitCode = Artisan::call('ai-pvg:triage');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Scored articles', $output);
        $this->assertStringContainsString('Composite', $output);
        $this->assertStringContainsString($ready->title, $output);
        $this->assertStringContainsString('Scored: 2 article(s).', $output);

        $this->assertDatabaseCount('news_scores', 3);
        $this->assertDatabaseHas('news_scores', ['news_id' => $ready->getKey()]);
        $this->assertDatabaseHas('news_scores', ['news_id' => $secondReady->getKey()]);
        $this->assertDatabaseMissing('news_scores', ['news_id' => $draft->getKey()]);

        $preserved = NewsScore::query()->where('news_id', $alreadyScored->getKey())->firstOrFail();
        $this->assertSame(50.0, (float) $preserved->composite_score);

        // Scoring alone never clusters or greenlights.
        $this->assertDatabaseCount('story_clusters', 0);
        $this->assertDatabaseCount('automation_runs', 0);
        Http::assertNothingSent();
    }

    public function test_run_option_scores_without_running_clustering(): void
    {
        $news = $this->makeNewsWithSource('Run flag technology story', NewsSource::factory()->create(), [
            'status' => NewsStatus::Ready->value,
        ]);

        $exitCode = Artisan::call('ai-pvg:triage', ['--run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Scored: 1 article(s).', $output);
        $this->assertStringContainsString('Clustering and auto-greenlight skipped', $output);
        $this->assertDatabaseHas('news_scores', ['news_id' => $news->getKey()]);
        $this->assertDatabaseCount('story_clusters', 0);
        $this->assertDatabaseCount('automation_runs', 0);
        Http::assertNothingSent();
    }

    public function test_cluster_option_groups_scored_news_and_runs_auto_greenlight(): void
    {
        config(['automation.dry_run' => true]);

        $news = $this->makeClusterableNews();
        $this->makeNewsScore($news[0], 95.0);

        $exitCode = Artisan::call('ai-pvg:triage', ['--cluster' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Story clusters', $output);
        $this->assertStringContainsString('Auto-greenlight', $output);
        $this->assertStringContainsString('auto_greenlight', $output);
        $this->assertStringContainsString('planned script', $output);

        $this->assertDatabaseCount('story_clusters', 1);
        $this->assertDatabaseCount('story_cluster_news', 3);
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertDatabaseCount('video_scripts', 0);
        $this->assertDatabaseCount('approval_gates', 0);

        $run = AutomationRun::query()->firstOrFail();
        $this->assertTrue((bool) $run->dry_run);
        $this->assertNotNull(data_get($run->meta, 'planned_script'));

        // --cluster alone must not create scores.
        $this->assertDatabaseCount('news_scores', 1);
        Http::assertNothingSent();
    }

    public function test_dry_run_runs_all_stages_but_rolls_back_every_write(): void
    {
        config(['automation.dry_run' => false]);

        $news = $this->makeClusterableNews();
        $this->makeNewsScore($news[0], 95.0);

        $exitCode = Artisan::call('ai-pvg:triage', [
            '--run' => true,
            '--cluster' => true,
            '--dry-run' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Scored: 2 article(s).', $output);
        $this->assertStringContainsString('Story clusters', $output);
        $this->assertStringContainsString('auto_greenlight', $output);
        $this->assertStringContainsString('planned script', $output);
        $this->assertStringContainsString('transaction rolled back', $output);

        // The pre-existing score survived; every write from this command did not.
        $this->assertDatabaseCount('news_scores', 1);
        $this->assertDatabaseHas('news_scores', ['news_id' => $news[0]->getKey()]);
        $this->assertDatabaseMissing('news_scores', ['news_id' => $news[1]->getKey()]);
        $this->assertDatabaseMissing('news_scores', ['news_id' => $news[2]->getKey()]);
        $this->assertDatabaseCount('story_clusters', 0);
        $this->assertDatabaseCount('story_cluster_news', 0);
        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertDatabaseCount('video_scripts', 0);
        $this->assertDatabaseCount('approval_gates', 0);

        // The command-scoped dry-run override must not leak into the process.
        $this->assertFalse((bool) config('automation.dry_run'));
        Http::assertNothingSent();
    }

    public function test_command_fails_cleanly_when_the_triage_feature_flag_is_disabled(): void
    {
        FeatureFlag::setFlag('triage.news_scoring_enabled', false);

        $news = $this->makeNewsWithSource('Disabled triage story', NewsSource::factory()->create(), [
            'status' => NewsStatus::Ready->value,
        ]);

        $exitCode = Artisan::call('ai-pvg:triage');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Triage is disabled', $output);
        $this->assertStringContainsString('triage.news_scoring_enabled', $output);
        $this->assertDatabaseMissing('news_scores', ['news_id' => $news->getKey()]);
        $this->assertDatabaseCount('story_clusters', 0);
        $this->assertDatabaseCount('automation_runs', 0);
        Http::assertNothingSent();
    }

    /**
     * @return array<int, News>
     */
    private function makeClusterableNews(): array
    {
        $news = [];

        for ($index = 0; $index < 3; $index++) {
            $news[] = $this->makeNewsWithSource(
                'A shared AI policy story across sources',
                NewsSource::factory()->create(),
                [
                    'status' => NewsStatus::Ready->value,
                    'published_at' => now()->subMinutes($index + 1),
                ],
            );
        }

        return $news;
    }
}
