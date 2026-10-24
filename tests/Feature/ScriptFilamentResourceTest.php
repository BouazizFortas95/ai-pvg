<?php

namespace Tests\Feature;

use App\Enums\ScriptFactCheckVerdict;
use App\Filament\Resources\ScriptFactChecks\Pages\ListScriptFactChecks;
use App\Filament\Resources\ScriptFactChecks\ScriptFactCheckResource;
use App\Filament\Resources\ScriptVariants\Pages\ListScriptVariants;
use App\Filament\Resources\ScriptVariants\ScriptVariantResource;
use App\Models\User;
use App\Services\AI\ScriptPipeline\ScriptPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\BuildsScriptFixtures;
use Tests\TestCase;

class ScriptFilamentResourceTest extends TestCase
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

    public function test_script_variant_resource_is_read_only_and_reachable(): void
    {
        $result = app(ScriptPipelineService::class)->runPipeline($this->makeScriptCluster());
        $variant = $result->script->variants()->orderBy('hook_index')->firstOrFail();

        $this->assertFalse(ScriptVariantResource::canCreate());
        $this->assertFalse(ScriptVariantResource::canEdit($variant));
        $this->assertFalse(ScriptVariantResource::canDelete($variant));
        $this->assertFalse(ScriptVariantResource::canDeleteAny());
        $this->assertFalse(ScriptVariantResource::hasPage('create'));
        $this->assertFalse(ScriptVariantResource::hasPage('edit'));
        $this->assertSame(['index', 'view'], array_keys(ScriptVariantResource::getPages()));
        $this->assertSame('3', ScriptVariantResource::getNavigationBadge());

        $this->get(ScriptVariantResource::getUrl('index'))->assertSuccessful();
        $this->get(ScriptVariantResource::getUrl('view', ['record' => $variant]))->assertSuccessful();

        Livewire::test(ListScriptVariants::class)
            ->assertCanSeeTableRecords([$variant])
            ->assertTableColumnExists('hook_index')
            ->assertTableColumnExists('content')
            ->assertTableColumnExists('selected')
            ->assertTableColumnExists('source_news_ids');
    }

    public function test_script_fact_check_resource_is_read_only_and_surfaces_blocking_verdicts(): void
    {
        $result = app(ScriptPipelineService::class)->runPipeline($this->makeScriptCluster());
        $script = $result->script;
        $checks = $script->factChecks()->orderBy('id')->get();
        $check = $checks->firstOrFail();

        $this->assertNull(ScriptFactCheckResource::getNavigationBadge());

        $check->forceFill(['verdict' => ScriptFactCheckVerdict::Uncertain])->save();

        $this->assertFalse(ScriptFactCheckResource::canCreate());
        $this->assertFalse(ScriptFactCheckResource::canEdit($check));
        $this->assertFalse(ScriptFactCheckResource::canDelete($check));
        $this->assertFalse(ScriptFactCheckResource::canDeleteAny());
        $this->assertFalse(ScriptFactCheckResource::hasPage('create'));
        $this->assertFalse(ScriptFactCheckResource::hasPage('edit'));
        $this->assertSame(['index', 'view'], array_keys(ScriptFactCheckResource::getPages()));
        $this->assertSame('1', ScriptFactCheckResource::getNavigationBadge());

        $this->get(ScriptFactCheckResource::getUrl('index'))->assertSuccessful();
        $this->get(ScriptFactCheckResource::getUrl('view', ['record' => $check]))->assertSuccessful();

        Livewire::test(ListScriptFactChecks::class)
            ->assertCanSeeTableRecords([$check])
            ->assertTableColumnExists('claim')
            ->assertTableColumnExists('verdict')
            ->assertTableColumnExists('source_news_ids')
            ->assertTableColumnExists('critic_score');
    }
}
