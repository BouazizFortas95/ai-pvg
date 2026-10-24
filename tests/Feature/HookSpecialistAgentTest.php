<?php

namespace Tests\Feature;

use App\Enums\ScriptHookType;
use App\Models\NewsSource;
use App\Services\AI\ScriptPipeline\HookSpecialistAgent;
use App\Services\AI\ScriptPipeline\ScriptSourceFactory;
use App\Services\Automation\Clustering\TextSimilarityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsScriptFixtures;
use Tests\TestCase;

class HookSpecialistAgentTest extends TestCase
{
    use BuildsScriptFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'automation.script_agents.enabled' => true,
            'automation.script_agents.use_ai' => false,
            'automation.script_agents.hook_word_limit' => 12,
        ]);
    }

    public function test_cluster_hooks_are_three_distinct_grounded_and_deterministic(): void
    {
        $cluster = $this->makeScriptCluster();
        $source = app(ScriptSourceFactory::class)->fromModel($cluster);
        $agent = app(HookSpecialistAgent::class);

        $hooks = $agent->generate($source);
        $again = $agent->generate($source);

        $this->assertCount(3, $hooks);
        $this->assertSame(array_column($hooks, 'content'), array_column($again, 'content'));
        $this->assertCount(3, array_unique(array_column($hooks, 'content')));
        $this->assertSame(
            [ScriptHookType::Curiosity->value, ScriptHookType::Stat->value, ScriptHookType::Question->value],
            array_column($hooks, 'hook_type'),
        );

        $similarity = app(TextSimilarityService::class);

        foreach ($hooks as $hook) {
            $this->assertLessThanOrEqual(12, count(preg_split('/\s+/u', trim($hook['content'])) ?: []));
            $this->assertNotSame([], $hook['source_news_ids']);
            $this->assertSame('deterministic-local', $hook['provider']);

            $evidence = $source->evidenceFor($hook['source_news_ids']);
            $evidenceTokens = array_values(array_unique($similarity->tokenize($evidence)));
            $hookTokens = array_values(array_unique($similarity->tokenize($hook['content'])));

            $this->assertSame([], array_values(array_diff($hookTokens, $evidenceTokens)), 'Hook tokens must be grounded in cited sources.');
        }
    }

    public function test_single_news_still_produces_three_distinct_grounded_hooks(): void
    {
        $news = $this->makeNewsWithSource(
            'Helios lab reports solid-state battery milestone',
            NewsSource::factory()->create(),
            ['summary' => 'Helios lab reports a solid-state battery milestone with 900 cycles.'],
        );
        $source = app(ScriptSourceFactory::class)->fromModel($news);

        $hooks = app(HookSpecialistAgent::class)->generate($source);

        $this->assertCount(3, $hooks);
        $this->assertCount(3, array_unique(array_column($hooks, 'content')));

        foreach ($hooks as $hook) {
            $this->assertSame([(int) $news->getKey()], $hook['source_news_ids']);
        }
    }
}
