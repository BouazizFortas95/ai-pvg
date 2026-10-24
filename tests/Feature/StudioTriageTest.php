<?php

namespace Tests\Feature;

use App\Livewire\StudioDashboard;
use App\Models\News;
use App\Models\NewsSource;
use App\Models\StoryCluster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\BuildsTriageFixtures;
use Tests\TestCase;

class StudioTriageTest extends TestCase
{
    use BuildsTriageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'automation.triage.enabled' => true,
            'automation.dry_run' => false,
            'automation.news_scoring.auto_greenlight_min' => 85,
            'automation.news_scoring.review_band_min' => 60,
        ]);
    }

    public function test_studio_renders_triage_breakdowns_and_cluster_attribution_without_publishing_controls(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$cluster] = $this->clusterWithScore(90.0);

        $response = $this->get('/studio');

        $response->assertSuccessful();
        $response->assertSee('News Triage');
        $response->assertSee($cluster->canonical_title);
        $response->assertSee('Generate script');
        $response->assertSee('Score breakdown');
        $response->assertSee('Grounded fact sheet');
        $response->assertDontSee('Publish to YouTube');
        $response->assertDontSee('Public publish');
    }

    public function test_generate_script_is_admin_only_and_idempotent(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        [$cluster] = $this->clusterWithScore(90.0);

        Livewire::test(StudioDashboard::class)
            ->call('generateScript', $cluster->getKey())
            ->assertSet('triageMessage', 'Draft VideoScript created by auto-greenlight.');

        $this->assertDatabaseCount('video_scripts', 1);
        $this->assertDatabaseCount('automation_runs', 1);

        Livewire::test(StudioDashboard::class)
            ->call('generateScript', $cluster->getKey())
            ->assertSet('triageMessage', 'Existing auto-greenlight script returned; no duplicate was created.');

        $this->assertDatabaseCount('video_scripts', 1);
        $this->assertDatabaseCount('automation_runs', 1);
        Http::assertNothingSent();
    }

    public function test_generate_script_fails_closed_when_triage_is_disabled(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        config(['automation.triage.enabled' => false]);
        [$cluster] = $this->clusterWithScore(95.0);

        Livewire::test(StudioDashboard::class)
            ->call('generateScript', $cluster->getKey())
            ->assertSet('triageMessage', fn (string $message): bool => str_contains($message, 'disabled'));

        $this->assertDatabaseCount('video_scripts', 0);
        Http::assertNothingSent();
    }

    public function test_regular_users_cannot_trigger_script_generation(): void
    {
        $this->actingAs(User::factory()->create());
        [$cluster] = $this->clusterWithScore(95.0);

        Livewire::test(StudioDashboard::class)
            ->call('generateScript', $cluster->getKey())
            ->assertSet('triageMessage', 'Only administrators can request a triage script.');

        $this->assertDatabaseCount('video_scripts', 0);
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
                'Studio triage story '.$i,
                NewsSource::factory()->create(),
                ['published_at' => now()->subMinutes($i + 1)],
            );
        }

        $cluster = $this->makeStoryCluster($news);
        $this->makeNewsScore($news[0], $composite);

        return [$cluster->fresh(['news.newsScore']), $news];
    }
}
