<?php

namespace Tests\Support;

use App\Models\FeatureFlag;
use App\Models\NewsSource;
use App\Models\StoryCluster;

trait BuildsScriptFixtures
{
    use BuildsTriageFixtures;

    protected function enableScriptAgents(): void
    {
        config([
            'automation.script_agents.enabled' => true,
            'automation.dry_run' => false,
            'automation.script_agents.use_ai' => false,
            'automation.script_agents.target_duration' => 45,
            'automation.script_agents.duration_tolerance' => 5,
            'automation.script_agents.hook_word_limit' => 12,
            'automation.script_agents.claim_coverage_threshold' => 0.30,
        ]);

        FeatureFlag::setFlag('script_agents.enabled', true);
    }

    protected function makeScriptCluster(int $members = 3): StoryCluster
    {
        $news = [];

        for ($index = 0; $index < $members; $index++) {
            $news[] = $this->makeNewsWithSource(
                "Helios lab reports solid-state battery milestone {$index}",
                NewsSource::factory()->create(),
                [
                    'summary' => "Helios lab reports solid-state battery milestone {$index} with 900 cycles.",
                    'content' => "Helios lab reports solid-state battery milestone {$index} with 900 cycles. "
                        .'Independent testers confirmed the cells retained capacity.',
                    'published_at' => now()->subMinutes($index + 1),
                ],
            );
        }

        return $this->makeStoryCluster($news, [
            'canonical_title' => 'Helios lab reports solid-state battery breakthrough',
            'canonical_summary' => (string) $news[0]->summary,
        ]);
    }
}
