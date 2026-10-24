<?php

namespace Tests\Feature;

use App\Filament\Resources\NewsScores\NewsScoreResource;
use App\Filament\Resources\NewsScores\Pages\ListNewsScores;
use App\Filament\Resources\StoryClusters\Pages\ListStoryClusters;
use App\Filament\Resources\StoryClusters\StoryClusterResource;
use App\Models\NewsSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\BuildsTriageFixtures;
use Tests\TestCase;

class TriageFilamentResourceTest extends TestCase
{
    use BuildsTriageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_news_score_resource_is_read_only_and_reachable(): void
    {
        $news = $this->makeNewsWithSource('A scored news article', NewsSource::factory()->create());
        $score = $this->makeNewsScore($news, 90);

        $this->assertFalse(NewsScoreResource::canCreate());
        $this->assertFalse(NewsScoreResource::canEdit($score));
        $this->assertFalse(NewsScoreResource::canDelete($score));
        $this->assertFalse(NewsScoreResource::canDeleteAny());
        $this->assertFalse(NewsScoreResource::hasPage('create'));
        $this->assertFalse(NewsScoreResource::hasPage('edit'));
        $this->assertSame(['index', 'view'], array_keys(NewsScoreResource::getPages()));
        $this->assertSame('1', NewsScoreResource::getNavigationBadge());

        $this->get(NewsScoreResource::getUrl('index'))->assertSuccessful();
        $this->get(NewsScoreResource::getUrl('view', ['record' => $score]))->assertSuccessful();

        Livewire::test(ListNewsScores::class)
            ->assertCanSeeTableRecords([$score])
            ->assertTableColumnExists('composite_score')
            ->assertTableColumnExists('band')
            ->assertTableColumnExists('velocity_score');
    }

    public function test_story_cluster_resource_is_read_only_and_reachable(): void
    {
        $news = [];

        for ($i = 0; $i < 3; $i++) {
            $news[] = $this->makeNewsWithSource(
                'Cluster resource story',
                NewsSource::factory()->create(),
                ['published_at' => now()->subMinutes($i + 1)],
            );
        }

        $cluster = $this->makeStoryCluster($news);

        $this->assertFalse(StoryClusterResource::canCreate());
        $this->assertFalse(StoryClusterResource::canEdit($cluster));
        $this->assertFalse(StoryClusterResource::canDelete($cluster));
        $this->assertFalse(StoryClusterResource::canDeleteAny());
        $this->assertFalse(StoryClusterResource::hasPage('create'));
        $this->assertFalse(StoryClusterResource::hasPage('edit'));
        $this->assertSame(['index', 'view'], array_keys(StoryClusterResource::getPages()));
        $this->assertSame('1', StoryClusterResource::getNavigationBadge());

        $this->get(StoryClusterResource::getUrl('index'))->assertSuccessful();
        $this->get(StoryClusterResource::getUrl('view', ['record' => $cluster]))->assertSuccessful();

        Livewire::test(ListStoryClusters::class)
            ->assertCanSeeTableRecords([$cluster])
            ->assertTableColumnExists('canonical_title')
            ->assertTableColumnExists('source_count')
            ->assertTableColumnExists('confidence');
    }
}
