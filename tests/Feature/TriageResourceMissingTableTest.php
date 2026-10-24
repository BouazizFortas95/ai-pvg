<?php

namespace Tests\Feature;

use App\Filament\Resources\NewsScores\NewsScoreResource;
use App\Filament\Resources\StoryClusters\StoryClusterResource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TriageResourceMissingTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_triage_resources_fail_closed_when_tables_are_missing(): void
    {
        $this->assertFalse(Schema::hasTable('news_scores'));
        $this->assertFalse(Schema::hasTable('story_clusters'));
        $this->assertFalse(Schema::hasTable('story_cluster_news'));

        $this->assertFalse(NewsScoreResource::canAccess());
        $this->assertNull(NewsScoreResource::getNavigationBadge());
        $this->assertFalse(StoryClusterResource::canAccess());
        $this->assertNull(StoryClusterResource::getNavigationBadge());
    }
}
