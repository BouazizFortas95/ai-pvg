<?php

namespace Tests\Feature;

use App\Models\VariationHistory;
use App\Services\Avatar\CharacterGenerator;
use App\Services\Avatar\VariationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AvatarM2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_character_generator_determinism(): void
    {
        $gen = new CharacterGenerator;
        $spec1 = $gen->generateFromSeed(101);
        $spec2 = $gen->generateFromSeed(101);

        $this->assertSame($spec1, $spec2);
    }

    public function test_fingerprint_is_64_char_sha256(): void
    {
        $gen = new CharacterGenerator;
        $spec = $gen->generateFromSeed(42);
        $fp = CharacterGenerator::fingerprint($spec);

        $this->assertSame(64, strlen($fp));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fp);
    }

    public function test_fingerprint_determinism(): void
    {
        $gen = new CharacterGenerator;
        $specA = $gen->generateFromSeed(42);
        $specB = $gen->generateFromSeed(42);

        $this->assertSame(CharacterGenerator::fingerprint($specA), CharacterGenerator::fingerprint($specB));
    }

    public function test_category_distance_identical_is_zero(): void
    {
        $gen = new CharacterGenerator;
        $spec = $gen->generateFromSeed(55);

        $this->assertSame(0, CharacterGenerator::categoryDistance($spec, $spec));
    }

    public function test_category_distance_counts_differences(): void
    {
        $specA = [
            'head_shape' => 'oval',
            'skin_tone' => '#FDDBB4',
            'hair_style' => 'short_neat',
            'hair_color' => '#1A0A00',
        ];
        $specB = [
            'head_shape' => 'round',      // diff 1
            'skin_tone' => '#FDDBB4',
            'hair_style' => 'curly_afro',  // diff 2
            'hair_color' => '#D4AF37',    // diff 3
        ];

        $this->assertSame(3, CharacterGenerator::categoryDistance($specA, $specB));
    }

    public function test_variation_engine_infinite_mode_records_history(): void
    {
        $engine = app(VariationEngine::class);

        $countBefore = VariationHistory::count();
        $result = $engine->selectCharacter(202, 'run_test_01');

        $this->assertNotEmpty($result['character_id']);
        $this->assertNotEmpty($result['fingerprint']);
        $this->assertSame(202, $result['seed']);
        $this->assertSame('infinite', $result['mode']);
        $this->assertSame($countBefore + 1, VariationHistory::count());

        $latest = VariationHistory::latest('id')->first();
        $this->assertSame('run_test_01', $latest->run_id);
        $this->assertSame($result['fingerprint'], $latest->variation_fingerprint);
    }

    public function test_variation_engine_avoids_too_similar_past_character(): void
    {
        $gen = new CharacterGenerator;
        $specSeed10 = $gen->generateFromSeed(10);
        $fpSeed10 = CharacterGenerator::fingerprint($specSeed10);

        // Pre-seed history with the exact character from seed 10
        VariationHistory::create([
            'run_id' => 'pre_existing',
            'seed' => 10,
            'variation_fingerprint' => $fpSeed10,
            'categories_json' => $specSeed10,
        ]);

        $engine = app(VariationEngine::class);

        // Selecting with seed 10 must detect collision and derive an alternative seed
        $result = $engine->selectCharacter(10, 'run_collision_test', ['min_diff' => 4]);

        $this->assertNotSame($fpSeed10, $result['fingerprint']);
        $this->assertGreaterThan(1, $result['attempts']);

        $distance = CharacterGenerator::categoryDistance($result['spec'], $specSeed10);
        $this->assertGreaterThanOrEqual(4, $distance);
    }

    public function test_variation_engine_fixed_mode_locks_identity(): void
    {
        $engine = app(VariationEngine::class);

        // Run 1 with fixed mode
        $res1 = $engine->selectCharacter(101, 'run_fixed_1', ['mode' => 'fixed', 'character_id' => 'anchor_dan']);
        // Run 2 with different seed but same character_id
        $res2 = $engine->selectCharacter(999, 'run_fixed_2', ['mode' => 'fixed', 'character_id' => 'anchor_dan']);

        $this->assertSame('anchor_dan', $res1['character_id']);
        $this->assertSame('anchor_dan', $res2['character_id']);
        $this->assertSame($res1['spec'], $res2['spec']);
        $this->assertSame($res1['fingerprint'], $res2['fingerprint']);
    }

    public function test_three_distinct_seeds_satisfy_pairwise_uniqueness(): void
    {
        $gen = new CharacterGenerator;
        $seeds = [101, 202, 303];
        $specs = array_map(fn ($s) => $gen->generateFromSeed($s), $seeds);

        for ($i = 0; $i < count($specs); $i++) {
            for ($j = $i + 1; $j < count($specs); $j++) {
                $dist = CharacterGenerator::categoryDistance($specs[$i], $specs[$j]);
                $this->assertGreaterThanOrEqual(
                    4,
                    $dist,
                    "Seeds {$seeds[$i]} and {$seeds[$j]} differ by only {$dist} categories (expected >= 4)"
                );
            }
        }
    }

    public function test_verify_command_with_count_three_executes_cleanly(): void
    {
        $outputDir = storage_path('app/hyperframes/test_m2_verify_cli');

        $this->artisan('ai-pvg:verify-avatar', [
            '--count' => 3,
            '--seed' => 101,
            '--execute' => true,
            '--force' => true,
            '--output' => $outputDir,
        ])
            ->expectsOutputToContain('Pairwise Category Distance Matrix')
            ->expectsOutputToContain('M2 PASS')
            ->assertExitCode(0);

        // Verify compositions were generated
        $this->assertFileExists($outputDir.'/char_0/compositions/presenter.html');
        $this->assertFileExists($outputDir.'/char_1/compositions/presenter.html');
        $this->assertFileExists($outputDir.'/char_2/compositions/presenter.html');

        // Cleanup test directory
        $this->deleteDir($outputDir);
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            is_dir($path) ? $this->deleteDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
