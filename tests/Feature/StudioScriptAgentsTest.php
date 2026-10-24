<?php

namespace Tests\Feature;

use App\Enums\ScriptFactCheckVerdict;
use App\Enums\UserRole;
use App\Enums\VideoScriptStatus;
use App\Livewire\StudioDashboard;
use App\Models\User;
use App\Services\AI\ScriptPipeline\ScriptPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsScriptFixtures;
use Tests\TestCase;

class StudioScriptAgentsTest extends TestCase
{
    use BuildsScriptFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->enableScriptAgents();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_studio_shows_hooks_directive_preview_and_fact_check_warnings_without_publishing_controls(): void
    {
        $result = app(ScriptPipelineService::class)->runPipeline($this->makeScriptCluster());
        $script = $result->script->fresh();
        $hooks = $script->variants()->where('variant_type', 'hook')->orderBy('hook_index')->get();
        $blocked = $script->factChecks()->firstOrFail();
        $blocked->forceFill([
            'verdict' => ScriptFactCheckVerdict::Unsupported,
            'evidence' => 'No supporting member news text was found.',
        ])->save();

        $component = Livewire::test(StudioDashboard::class)
            ->assertSee('Script Agents')
            ->assertSee($hooks[0]->content)
            ->assertSee('Unsupported')
            ->assertSee($blocked->claim)
            ->assertSee('No supporting member news text was found.')
            ->assertSee('#'.implode(', #', array_map('intval', $blocked->source_news_ids)))
            ->assertSee('Preview directives')
            ->assertDontSee('Publish video')
            ->assertDontSee('Upload to YouTube');

        $component->call('previewHook', $hooks[1]->getKey())
            ->assertSet('previewHookIndex', 2)
            ->assertSee('Scene directive preview')
            ->assertSee('Directive diff vs persisted script');
    }

    public function test_admin_hook_selection_updates_exactly_one_variant(): void
    {
        $result = app(ScriptPipelineService::class)->runPipeline($this->makeScriptCluster());
        $script = $result->script->fresh();
        $hooks = $script->variants()->orderBy('hook_index')->get();
        $target = $hooks->firstWhere('hook_index', 2);

        Livewire::test(StudioDashboard::class)
            ->call('selectHook', $target->getKey())
            ->assertSet('previewHookIndex', 2)
            ->assertSee('selected for script');

        $this->assertSame(1, $script->variants()->where('selected', true)->count());
        $this->assertSame(2, (int) $script->variants()->where('selected', true)->value('hook_index'));
        $this->assertSame(2, (int) $target->fresh()->hook_index);
    }

    public function test_hook_selection_refuses_approved_scripts(): void
    {
        $result = app(ScriptPipelineService::class)->runPipeline($this->makeScriptCluster());
        $script = $result->script->fresh();
        $script->forceFill([
            'status' => VideoScriptStatus::Approved,
            'approved_at' => now(),
        ])->save();
        $selected = $script->variants()->where('selected', true)->firstOrFail();
        $other = $script->variants()->where('selected', false)->firstOrFail();

        Livewire::test(StudioDashboard::class)
            ->call('selectHook', $other->getKey())
            ->assertSee('Approved scripts are protected');

        $this->assertSame($selected->getKey(), $script->variants()->where('selected', true)->value('id'));
        $this->assertSame(VideoScriptStatus::Approved, $script->fresh()->status);
    }

    public function test_non_admin_cannot_select_a_hook(): void
    {
        $result = app(ScriptPipelineService::class)->runPipeline($this->makeScriptCluster());
        $script = $result->script->fresh();
        $other = $script->variants()->where('selected', false)->firstOrFail();

        $viewer = User::factory()->create(['role' => UserRole::User]);
        $this->actingAs($viewer);

        Livewire::test(StudioDashboard::class)
            ->call('selectHook', $other->getKey())
            ->assertSee('Only administrators can select a hook variant.');

        $this->assertSame(1, $script->variants()->where('selected', true)->count());
        $this->assertFalse((bool) $other->fresh()->selected);
    }

    public function test_studio_fails_closed_when_script_agent_tables_are_missing(): void
    {
        Schema::dropIfExists('script_fact_checks');
        Schema::dropIfExists('script_variants');

        Livewire::test(StudioDashboard::class)
            ->assertSee('Script agent tables are not available')
            ->assertDontSee('Grounded hooks');
    }
}
