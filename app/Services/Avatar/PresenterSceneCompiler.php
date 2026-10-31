<?php

namespace App\Services\Avatar;

use App\Services\Rendering\Audio\AudioProbe;
use App\Services\Rendering\HyperFramesCompositionBuilder;
use App\Services\Rendering\HyperFramesRenderService;
use Illuminate\Support\Facades\File;

/**
 * PresenterSceneCompiler connects the code-made avatar into the HyperFrames
 * composition pipeline as a synchronized sub-composition track.
 *
 * Supports:
 * - Mode A (Infinite Variety): unique presenter character + dynamic styling
 * - Mode B (Fixed Anchor): locked anchor identity + run-specific style variations
 * - Full audio timeline alignment (narration, music ducking, SFX, lip-sync)
 */
class PresenterSceneCompiler
{
    public function __construct(
        protected HyperFramesCompositionBuilder $builder,
        protected VariationEngine $variationEngine,
        protected VisemeMapper $visemeMapper,
        protected EmotionAnnotator $emotionAnnotator,
        protected PresenterCompositionWriter $writer,
        protected ?HyperFramesRenderService $renderer = null,
        protected ?AudioProbe $audioProbe = null,
    ) {
        $this->renderer = $renderer ?? app(HyperFramesRenderService::class);
        $this->audioProbe = $audioProbe ?? app(AudioProbe::class);
    }

    /**
     * Compile a full HyperFrames project featuring the animated presenter avatar.
     *
     * @param  array<string, mixed>  $audioTimeline  Decoded audio_timeline.json
     * @param  array<string, mixed>  $options
     * @return array{projectDir: string, indexHtml: string, presenterHtml: string, runId: string, duration: float, character: array<string, mixed>, style: array<string, mixed>, emotionTimeline: array<int, array<string, mixed>>, audioMixPath: ?string, manifest: array<string, mixed>}
     */
    public function compile(array $audioTimeline, array $options = []): array
    {
        $duration = (float) ($audioTimeline['total_duration'] ?? 24.0);
        $fps = (int) ($options['fps'] ?? config('automation.render.hyperframes.fps', 30));
        $seed = (int) ($options['seed'] ?? 42);
        $mode = (string) ($options['mode'] ?? config('automation.avatar.mode', 'infinite'));
        $variationLevel = (string) ($options['variation'] ?? config('automation.avatar.variation', 'medium'));
        $preset = (string) ($options['preset'] ?? '9:16');
        $theme = (string) ($options['theme'] ?? 'cyber');
        $runId = (string) ($options['run_id'] ?? ('avatar_'.date('Ymd_His').'_'.$mode.'_'.$seed));

        // 1. Resolve character and style specs via VariationEngine
        $variationOptions = [
            'mode' => $mode,
            'variation' => $variationLevel,
            'record' => (bool) ($options['record'] ?? true),
        ];
        if (! empty($options['character_id'])) {
            $variationOptions['character_id'] = (string) $options['character_id'];
        }

        $characterResult = $this->variationEngine->selectCharacter($seed, $runId, $variationOptions);
        $charSpec = $characterResult['spec'];
        $styleSpec = $characterResult['style_spec'];

        // 2. Extract word timings and build lip-sync track
        $wordTimings = $this->extractWordTimings($audioTimeline);
        $lipSyncTrack = VisemeMapper::buildTrack($wordTimings, $duration, $fps);

        // 3. Annotate emotion beats and apply ToneGuard
        $narrationScenes = is_array($audioTimeline['narration'] ?? null) ? $audioTimeline['narration'] : [];
        $emotionTimeline = $this->emotionAnnotator->annotateScenes($narrationScenes);

        // 4. Prepare scenes for HyperFramesCompositionBuilder
        $scenes = [];
        foreach ($narrationScenes as $idx => $narr) {
            $sceneDur = (float) ($narr['duration'] ?? 4.0);
            $scenes[] = [
                'order' => $idx + 1,
                'title' => (string) ($narr['text'] ?? 'News Scene '.($idx + 1)),
                'headline' => (string) ($narr['text'] ?? ''),
                'description' => (string) ($narr['text'] ?? ''),
                'duration' => $sceneDur,
                'directives' => [
                    'sfx_cue' => $styleSpec['sfx_cue'] ?? '',
                    'highlight_text' => $styleSpec['tool_headline'] ?? '',
                ],
            ];
        }

        // Align scene durations to exact total audio duration so root duration matches avatar duration
        $scenesDuration = round(array_sum(array_column($scenes, 'duration')), 3);
        if ($duration > $scenesDuration && count($scenes) > 0) {
            $diff = round($duration - $scenesDuration, 3);
            $scenes[count($scenes) - 1]['duration'] = round($scenes[count($scenes) - 1]['duration'] + $diff, 3);
        }

        if ($scenes === []) {
            $scenes[] = [
                'order' => 1,
                'title' => (string) ($audioTimeline['title'] ?? 'AI-PVG Verified News Briefing'),
                'headline' => (string) ($audioTimeline['title'] ?? 'AI-PVG Verified News Briefing'),
                'description' => 'Automated grounded dispatch.',
                'duration' => $duration,
                'directives' => ['sfx_cue' => $styleSpec['sfx_cue'] ?? ''],
            ];
        }

        // 5. Audio mix asset resolution
        $audioAssets = [];
        $mixSourcePath = null;
        if (! empty($audioTimeline['mix']['path']) && is_file($audioTimeline['mix']['path'])) {
            $mixSourcePath = $audioTimeline['mix']['path'];
            $audioAssets[] = [
                'id' => 1,
                'kind' => 'mix',
                'source_path' => $mixSourcePath,
            ];
        } elseif (! empty($options['audio_mix']) && is_file($options['audio_mix'])) {
            $mixSourcePath = $options['audio_mix'];
            $audioAssets[] = [
                'id' => 1,
                'kind' => 'mix',
                'source_path' => $mixSourcePath,
            ];
        }

        // 6. Build base HyperFrames project with avatar host
        $avatarHost = [
            'id' => 'avatar-presenter',
            'composition_id' => 'pvg-presenter',
            'src' => 'compositions/presenter.html',
            'start' => '0',
            'duration' => (string) round($duration, 3),
            'track' => 5,
        ];

        $builderOptions = [
            'preset' => $preset,
            'theme' => $theme,
            'run_id' => $runId,
            'fps' => $fps,
            'word_timings' => $wordTimings,
            'captions_verified' => true,
            'audio_mix' => $mixSourcePath,
            'audio' => $audioAssets,
            'avatar_host' => $avatarHost,
        ];

        $built = $this->builder->buildFromPayload([
            'title' => (string) ($audioTimeline['title'] ?? 'AI-PVG Avatar Broadcast'),
            'language' => (string) ($audioTimeline['language'] ?? 'en'),
            'target_duration' => $duration,
            'scenes' => $scenes,
        ], $builderOptions);

        $projectDir = $built['projectDir'];

        // 7. Write presenter sub-composition into compositions/presenter.html
        $relPath = $this->writer->write(
            $charSpec,
            $lipSyncTrack,
            $audioTimeline,
            $projectDir,
            $seed,
            $fps,
            $styleSpec,
            $emotionTimeline
        );

        $presenterFullPath = $projectDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relPath);

        // If a specific output_dir was requested, sync files to it
        if (! empty($options['output_dir']) && $options['output_dir'] !== $projectDir) {
            File::ensureDirectoryExists($options['output_dir']);
            File::copyDirectory($projectDir, $options['output_dir']);
            $projectDir = $options['output_dir'];
            $presenterFullPath = $projectDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        }

        return [
            'projectDir' => $projectDir,
            'indexHtml' => (string) file_get_contents($projectDir.'/index.html'),
            'presenterHtml' => (string) file_get_contents($presenterFullPath),
            'runId' => $runId,
            'duration' => $duration,
            'character' => $characterResult,
            'style' => $styleSpec,
            'emotionTimeline' => $emotionTimeline,
            'audioMixPath' => $mixSourcePath,
            'manifest' => $built['manifest'] ?? [],
        ];
    }

    /**
     * Compile and render to an MP4 video file.
     *
     * @param  array<string, mixed>  $audioTimeline
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function compileAndRender(array $audioTimeline, string $outputVideoPath, array $options = []): array
    {
        $compiled = $this->compile($audioTimeline, $options);

        File::ensureDirectoryExists(dirname($outputVideoPath));

        $renderResult = $this->renderer->lintAndRender(
            ['projectDir' => $compiled['projectDir']],
            $outputVideoPath
        );

        return array_merge($compiled, [
            'outputVideo' => $outputVideoPath,
            'renderResult' => $renderResult,
            'metadata' => $renderResult['metadata'] ?? [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{word: string, startMs: int, endMs: int}>
     */
    protected function extractWordTimings(array $manifest): array
    {
        if (isset($manifest['word_timings']) && is_array($manifest['word_timings'])) {
            return $manifest['word_timings'];
        }

        $timings = [];
        if (isset($manifest['narration']) && is_array($manifest['narration'])) {
            foreach ($manifest['narration'] as $scene) {
                if (isset($scene['word_timings']) && is_array($scene['word_timings'])) {
                    foreach ($scene['word_timings'] as $wt) {
                        $timings[] = $wt;
                    }
                }
            }
        }

        return $timings;
    }
}
