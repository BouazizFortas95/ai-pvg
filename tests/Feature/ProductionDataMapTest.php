<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ProductionDataMapTest extends TestCase
{
    use RefreshDatabase;

    protected string $mapPath;

    protected array $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapPath = base_path('docs/PRODUCTION_DATA_MAP.md');
        $this->assertFileExists($this->mapPath, 'docs/PRODUCTION_DATA_MAP.md must exist in the repository.');

        $content = (string) file_get_contents($this->mapPath);

        // Extract YAML registry block
        preg_match('/```yaml\s+(data_map_registry:.*?)```/s', $content, $matches);
        $this->assertNotEmpty($matches, 'docs/PRODUCTION_DATA_MAP.md must contain a machine-readable data_map_registry YAML block.');

        $parsed = Yaml::parse($matches[1]);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('data_map_registry', $parsed);

        $this->registry = $parsed['data_map_registry'];
    }

    public function test_all_verified_tables_in_data_map_exist_in_database(): void
    {
        $tables = $this->registry['verified_tables'] ?? [];
        $this->assertNotEmpty($tables, 'Registry must define verified_tables.');

        foreach ($tables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Table '{$table}' is listed as VERIFIED in docs/PRODUCTION_DATA_MAP.md but does NOT exist in the database schema."
            );
        }
    }

    public function test_all_verified_config_keys_in_data_map_exist_in_application(): void
    {
        $keys = $this->registry['verified_config_keys'] ?? [];
        $this->assertNotEmpty($keys, 'Registry must define verified_config_keys.');

        foreach ($keys as $key) {
            $this->assertNotNull(
                config($key),
                "Config key '{$key}' is listed as VERIFIED in docs/PRODUCTION_DATA_MAP.md but resolves to null."
            );
        }

        // Publishing MUST remain strictly locked OFF
        $this->assertFalse(
            (bool) config('automation.publishing.enabled'),
            'Safety constraint violated: automation.publishing.enabled must be strictly false.'
        );
    }

    public function test_all_verified_base_paths_exist_on_filesystem(): void
    {
        $paths = $this->registry['verified_base_paths'] ?? [];
        $this->assertNotEmpty($paths, 'Registry must define verified_base_paths.');

        foreach ($paths as $path) {
            $fullPath = base_path($path);
            $this->assertTrue(
                is_dir($fullPath),
                "Directory '{$path}' is listed as VERIFIED in docs/PRODUCTION_DATA_MAP.md but does not exist at {$fullPath}."
            );
        }
    }

    public function test_data_map_explicitly_marks_publishing_locked_off(): void
    {
        $content = (string) file_get_contents($this->mapPath);
        $this->assertStringContainsString('automation.publishing.enabled = false', $content);
        $this->assertStringContainsString('LOCKED OFF', $content);
    }

    public function test_data_map_defines_honest_readiness_taxonomy_and_additive_tables(): void
    {
        $content = (string) file_get_contents($this->mapPath);

        // Additive planned tables must be documented
        $this->assertStringContainsString('motion_sources', $content);
        $this->assertStringContainsString('motion_recipes', $content);
        $this->assertStringContainsString('motion_components', $content);
        $this->assertStringContainsString('motion_component_usages', $content);
        $this->assertStringContainsString('voice_profiles', $content);
        $this->assertStringContainsString('production_manifests', $content);

        // Taxonomy states must be present
        $this->assertStringContainsString('VERIFIED', $content);
        $this->assertStringContainsString('PLANNED', $content);
    }
}
