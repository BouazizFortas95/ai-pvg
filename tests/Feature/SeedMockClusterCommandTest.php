<?php

namespace Tests\Feature;

use App\Enums\NewsStatus;
use App\Models\FeatureFlag;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsScore;
use App\Models\StoryCluster;
use App\Models\VideoRender;
use App\Models\VideoScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeedMockClusterCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'automation.triage.enabled' => true,
            'automation.dry_run' => false,
            'automation.publishing.enabled' => false,
            'automation.script_agents.enabled' => true,
            'automation.script_agents.use_ai' => false,
        ]);

        FeatureFlag::setFlag('triage.news_scoring_enabled', true);
        FeatureFlag::setFlag('script_agents.enabled', true);
    }

    public function test_seed_creates_articles_with_dynamic_categories_and_ready_status(): void
    {
        $exitCode = Artisan::call('ai-pvg:seed-mock-cluster', [
            '--cluster-size' => 3,
            '--skip-triage' => true,
            '--skip-script' => true,
            '--skip-render' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $this->assertSame(3, News::query()->where('status', NewsStatus::Ready->value)->count());
        $this->assertSame(3, News::query()->whereNotNull('news_category_id')->count());

        // Unknown labels are auto-created as dynamic categories.
        $this->assertDatabaseHas('news_categories', ['name' => 'Artificial Intelligence']);
        $this->assertDatabaseHas('news_categories', ['name' => 'Security']);
        $this->assertDatabaseHas('news_categories', ['name' => 'Open Source']);

        // Known labels are reused (no duplicate rows). Uncategorized exists
        // from the migration backfill, so 3 articles produce 4 categories.
        $this->assertSame(4, NewsCategory::query()->count());

        Http::assertNothingSent();
    }

    public function test_seed_respects_cluster_size_bounds(): void
    {
        $exitCode = Artisan::call('ai-pvg:seed-mock-cluster', [
            '--cluster-size' => 2,
            '--skip-triage' => true,
            '--skip-script' => true,
            '--skip-render' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, News::query()->count());
    }

    public function test_seed_runs_triage_and_forms_cross_category_cluster(): void
    {
        $exitCode = Artisan::call('ai-pvg:seed-mock-cluster', [
            '--cluster-size' => 5,
            '--skip-script' => true,
            '--skip-render' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $this->assertSame(5, News::query()->where('status', NewsStatus::Ready->value)->count());
        $this->assertSame(5, NewsScore::query()->count());

        // A cluster should form from the overlapping Nova-1 coverage.
        $this->assertSame(1, StoryCluster::query()->count());
        $cluster = StoryCluster::query()->firstOrFail();
        $this->assertGreaterThanOrEqual(3, (int) $cluster->source_count);
        $this->assertLessThanOrEqual(5, (int) $cluster->source_count);

        // Members span multiple dynamic categories.
        $categories = $cluster->news->pluck('newsCategory.name')->unique()->all();
        $this->assertGreaterThanOrEqual(2, count($categories));

        // Auto-greenlight triggered by high pre-score creates a video_script.
        $this->assertSame(1, VideoScript::query()->count());

        Http::assertNothingSent();
    }

    public function test_seed_runs_full_pipeline_without_rendering(): void
    {
        $exitCode = Artisan::call('ai-pvg:seed-mock-cluster', [
            '--cluster-size' => 5,
            '--skip-render' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $this->assertSame(5, News::query()->where('status', NewsStatus::Ready->value)->count());
        $this->assertSame(1, StoryCluster::query()->count());

        // Script pipeline ran and produced an approved script.
        $this->assertSame(1, VideoScript::query()->count());
        $script = VideoScript::query()->firstOrFail();
        $this->assertSame('approved', $script->status->value);
        $this->assertNotNull($script->approved_at);

        // Fact checks were generated for the grounded script.
        $this->assertGreaterThanOrEqual(3, $script->factChecks()->count());

        // No rendering was performed.
        $this->assertSame(0, VideoRender::query()->count());

        Http::assertNothingSent();
    }
}
