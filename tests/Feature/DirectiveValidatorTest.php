<?php

namespace Tests\Feature;

use App\Exceptions\Automation\ScriptDirectiveValidationException;
use App\Services\AI\ScriptPipeline\DirectiveValidator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DirectiveValidatorTest extends TestCase
{
    private DirectiveValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'automation.script_agents.duration_tolerance' => 5,
            'automation.script_agents.max_scene_seconds' => 12,
        ]);

        $this->validator = app(DirectiveValidator::class);
    }

    public function test_legacy_scenes_without_directives_pass_through_unchanged(): void
    {
        $scenes = [
            [
                'order' => 1,
                'type' => 'intro',
                'duration' => 5,
                'headline' => 'Legacy headline',
                'description' => 'Legacy scene',
                'narration' => 'Legacy narration without directives.',
                'visual_keywords' => ['legacy'],
            ],
            [
                'order' => 2,
                'type' => 'segment',
                'duration' => 40,
                'headline' => 'Legacy segment',
                'description' => 'Legacy scene',
                'narration' => 'Legacy narration continues.',
                'visual_keywords' => ['legacy'],
            ],
        ];

        $this->assertSame($scenes, $this->validator->validate($scenes, 45, [1, 2]));
    }

    public function test_valid_directives_pass_within_duration_tolerance(): void
    {
        $scenes = $this->validScenes();

        $this->assertSame($scenes, $this->validator->validate($scenes, 45, [1, 2, 3]));
        $this->assertSame($scenes, $this->validator->validate($scenes, 49, [1, 2, 3]));
    }

    public function test_mixed_scene_directive_presence_is_rejected(): void
    {
        $scenes = $this->validScenes();
        unset($scenes[1]['directives']);

        $errors = $this->validationErrors($scenes, 45, [1, 2, 3]);

        $this->assertStringContainsString('Directives must be present on every scene', $errors);
    }

    public function test_missing_and_unknown_directive_keys_are_rejected(): void
    {
        $scenes = $this->validScenes();
        unset($scenes[0]['directives']['sfx_cue']);
        $scenes[0]['directives']['extra_key'] = 'nope';

        $errors = $this->validationErrors($scenes, 45, [1, 2, 3]);

        $this->assertStringContainsString('missing: sfx_cue', $errors);
        $this->assertStringContainsString('unsupported keys: extra_key', $errors);
    }

    public function test_duration_mismatch_and_total_outside_tolerance_are_rejected(): void
    {
        $scenes = $this->validScenes();
        $scenes[0]['directives']['duration_seconds'] = 4;

        $errors = $this->validationErrors($scenes, 45, [1, 2, 3]);
        $this->assertStringContainsString('duration_seconds must equal its duration', $errors);

        $scenes = $this->validScenes();
        $errors = $this->validationErrors($scenes, 60, [1, 2, 3]);
        $this->assertStringContainsString('outside the 60s target', $errors);
    }

    public function test_invalid_camera_or_source_ids_are_rejected(): void
    {
        $scenes = $this->validScenes();
        $scenes[0]['directives']['camera_motion'] = 'orbit';
        $scenes[1]['source_news_ids'] = [99];

        $errors = $this->validationErrors($scenes, 45, [1, 2, 3]);

        $this->assertStringContainsString('camera_motion is invalid', $errors);
        $this->assertStringContainsString('outside the grounded cluster', $errors);
    }

    public function test_non_contiguous_orders_are_rejected(): void
    {
        $scenes = $this->validScenes();
        $scenes[1]['order'] = 5;

        $errors = $this->validationErrors($scenes, 45, [1, 2, 3]);

        $this->assertStringContainsString('contiguous order', $errors);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validScenes(): array
    {
        $scenes = [];

        $specs = [
            ['intro', 5, 'Grounded introduction', [1], 'slow_in', 'Solid-state battery', 'whoosh', 'urgent'],
            ['segment', 12, 'Grounded claim one', [1], 'pan_left', '900 cycles', 'impact', 'confident'],
            ['segment', 12, 'Grounded claim two', [2], 'push_in', 'Battery testing', 'none', 'curious'],
            ['segment', 11, 'Grounded claim three', [3], 'pan_right', 'Independent testers', 'impact', 'confident'],
            ['conclusion', 5, 'Grounded conclusion', [1, 2, 3], 'slow_out', 'Why it matters', 'riser', 'serious'],
        ];

        foreach ($specs as $index => [$type, $duration, $headline, $sourceIds, $camera, $highlight, $sfx, $tone]) {
            $scenes[] = [
                'order' => $index + 1,
                'type' => $type,
                'duration' => $duration,
                'headline' => $headline,
                'description' => 'Grounded scene',
                'narration' => $headline.'.',
                'claim' => $headline,
                'visual_keywords' => ['grounded'],
                'source_news_ids' => $sourceIds,
                'directives' => [
                    'camera_motion' => $camera,
                    'b_roll_query' => 'grounded b roll query',
                    'highlight_text' => $highlight,
                    'sfx_cue' => $sfx,
                    'emotion_tone' => $tone,
                    'duration_seconds' => $duration,
                ],
            ];
        }

        return $scenes;
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<int>  $validIds
     */
    private function validationErrors(array $scenes, int $target, array $validIds): string
    {
        try {
            $this->validator->validate($scenes, $target, $validIds);
        } catch (ScriptDirectiveValidationException $exception) {
            return $exception->getMessage();
        }

        $this->fail('Expected directive validation to fail.');
    }
}
