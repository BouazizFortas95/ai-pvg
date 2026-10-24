<?php

namespace Tests\Feature;

use App\Models\NewsSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsTriageFixtures;
use Tests\TestCase;

class TriageDiagnosticsTest extends TestCase
{
    use BuildsTriageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['automation.triage.enabled' => true]);
    }

    public function test_diagnostics_reports_g02_tables_and_read_only_counts(): void
    {
        $news = [];

        for ($i = 0; $i < 3; $i++) {
            $news[] = $this->makeNewsWithSource(
                'Diagnostics triage story '.$i,
                NewsSource::factory()->create(),
                ['published_at' => now()->subMinutes($i + 1)],
            );
        }

        $cluster = $this->makeStoryCluster($news);
        $this->makeNewsScore($news[0], 90);

        $this->artisan('ai-pvg:diagnostics')
            ->expectsOutputToContain('Automation tables: 7/7 OK')
            ->expectsOutputToContain('Triage enabled: YES (flag=triage.news_scoring_enabled)')
            ->expectsOutputToContain('Triage tables: 3/3 OK')
            ->expectsOutputToContain('Triage data: news_scores=1; story_clusters=1; story_cluster_news=3')
            ->assertSuccessful();

        $this->assertDatabaseHas('story_clusters', ['id' => $cluster->getKey()]);
        Http::assertNothingSent();
    }
}
