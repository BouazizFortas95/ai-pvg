<?php

namespace Tests\Feature;

use App\Filament\Resources\ScriptFactChecks\ScriptFactCheckResource;
use App\Filament\Resources\ScriptVariants\ScriptVariantResource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScriptResourceMissingTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_script_agent_resources_fail_closed_when_tables_are_missing(): void
    {
        $this->assertFalse(Schema::hasTable('script_variants'));
        $this->assertFalse(Schema::hasTable('script_fact_checks'));

        $this->assertFalse(ScriptVariantResource::canAccess());
        $this->assertNull(ScriptVariantResource::getNavigationBadge());
        $this->assertFalse(ScriptFactCheckResource::canAccess());
        $this->assertNull(ScriptFactCheckResource::getNavigationBadge());
    }
}
