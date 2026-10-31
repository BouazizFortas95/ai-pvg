<?php

namespace App\Services\Rendering;

use App\Models\AudioAsset;
use App\Models\CaptionTrack;
use App\Models\SceneAsset;
use App\Models\VideoScript;
use App\Services\Rendering\EditPlan\EditPlanValidator;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Deterministic Template Mode composition builder.
 *
 * Same script + options always produce the same HTML and asset names.
 * Compositions are written under storage/app/hyperframes/{run_id}/ so no two
 * renders share mutable files. No LLM, network, clock or random source is used.
 */
class HyperFramesCompositionBuilder
{
    public function __construct(
        protected ?string $templateDir = null,
        protected ?string $outputRoot = null,
    ) {
        $this->templateDir = $templateDir ?? HyperFramesRuntime::directory();
        $this->outputRoot = $outputRoot ?? HyperFramesRuntime::outputRoot();
    }

    /**
     * @param  array{preset?: string, theme?: string, run_id?: string, fps?: int, caption_track?: array<string, mixed>|null}  $options
     * @return array{html: string, projectDir: string, compositionId: string, runId: string, manifest: array<string, mixed>}
     */
    public function buildFromScript(VideoScript $script, array $options = []): array
    {
        $scenes = $this->normalizeScenes($script->scenes ?? [], (float) ($script->target_duration ?? 0));

        return $this->build([
            'title' => (string) $script->title,
            'language' => (string) ($script->language ?? 'en'),
            'scenes' => $scenes,
            'target_duration' => (float) ($script->target_duration ?? array_sum(array_column($scenes, 'duration'))),
            'script_id' => (int) $script->getKey(),
        ], $options, $this->resolveAssets($script), $this->resolveAudio($script), $this->resolveCaptionTrack($script, $options));
    }

    /**
     * Compile a validated edit plan into HTML. The plan is the single source
     * of truth for timing, transitions, kinetic type, captions and audio.
     *
     * @param  array<string, mixed>  $plan
     * @param  array{preset?: string, run_id?: string, fps?: int, audio_mix?: string, word_timings?: list<array<string, mixed>>, captions_verified?: bool, tts?: array<string, mixed>, transcription?: array<string, mixed>}  $options
     * @return array{html: string, projectDir: string, compositionId: string, runId: string, manifest: array<string, mixed>, plan: array<string, mixed>}
     */
    public function buildFromEditPlan(array $plan, array $options = []): array
    {
        (new EditPlanValidator)->assertValid($plan);

        $scenes = $this->normalizeScenes($plan['scenes'] ?? [], (float) ($plan['duration'] ?? 24));

        $audio = [];
        if (! empty($options['audio_mix']) && is_file($options['audio_mix'])) {
            $audio[] = [
                'id' => 1,
                'kind' => 'mix',
                'source_path' => $options['audio_mix'],
            ];
        } elseif (! empty($options['audio']) && is_array($options['audio'])) {
            $audio = $options['audio'];
        }

        $assets = $options['assets'] ?? [];
        $targetDuration = (float) ($plan['duration'] ?? array_sum(array_column($scenes, 'duration')));

        $built = $this->build([
            'title' => (string) ($plan['title'] ?? 'AI-PVG Professional Edit'),
            'language' => (string) ($plan['language'] ?? 'en'),
            'scenes' => $scenes,
            'target_duration' => $targetDuration,
            'script_id' => null,
        ], $options, $assets, $audio, null);

        $built['plan'] = $plan;

        return $built;
    }

    /**
     * Build a synthetic composition from a JSON payload (verification path).
     *
     * @param  array<string, mixed>  $payload
     * @param  array{preset?: string, theme?: string, run_id?: string, fps?: int, audio_mix?: string, word_timings?: list<array<string, mixed>>}  $options
     * @return array{html: string, projectDir: string, compositionId: string, runId: string, manifest: array<string, mixed>}
     */
    public function buildFromPayload(array $payload, array $options = []): array
    {
        $scenes = $this->normalizeScenes($payload['scenes'] ?? [], (float) ($payload['target_duration'] ?? 6));

        $audio = [];
        if (! empty($options['audio_mix']) && is_file($options['audio_mix'])) {
            $audio[] = [
                'id' => 1,
                'kind' => 'mix',
                'source_path' => $options['audio_mix'],
            ];
        } elseif (! empty($options['audio']) && is_array($options['audio'])) {
            $audio = $options['audio'];
        }

        $assets = $options['assets'] ?? [];

        return $this->build([
            'title' => (string) ($payload['title'] ?? 'AI-PVG'),
            'language' => (string) ($payload['language'] ?? 'en'),
            'scenes' => $scenes,
            'target_duration' => (float) ($payload['target_duration'] ?? array_sum(array_column($scenes, 'duration'))),
            'script_id' => null,
        ], $options, $assets, $audio, null);
    }

    /**
     * @param  array{title: string, language: string, scenes: list<array<string, mixed>>, target_duration: float, script_id: ?int}  $input
     * @param  array{preset?: string, theme?: string, run_id?: string, fps?: int}  $options
     * @param  list<array<string, mixed>>  $assets
     * @param  list<array<string, mixed>>  $audio
     * @return array{html: string, projectDir: string, compositionId: string, runId: string, manifest: array<string, mixed>}
     */
    protected function build(array $input, array $options, array $assets, array $audio, ?CaptionTrack $captionTrack): array
    {
        $preset = (string) ($options['preset'] ?? '9:16');
        [$width, $height] = $this->parsePreset($preset);
        $fps = max(1, (int) ($options['fps'] ?? config('automation.render.hyperframes.fps', 30)));
        $theme = $this->getTheme((string) ($options['theme'] ?? 'cyber'));
        $runId = $this->sanitizeRunId((string) ($options['run_id'] ?? ('script-'.($input['script_id'] ?? 'payload'))));
        $projectDir = $this->prepareOutput($runId);

        $scenes = $input['scenes'];
        $duration = max(1.0, round(array_sum(array_column($scenes, 'duration')), 3));
        $safe = $this->safeZones($preset, $width, $height);
        $fontFamily = 'Inter, system-ui, sans-serif';
        $compositionId = 'main-'.$runId;

        $sceneHosts = [];
        $sceneFiles = [];
        $mediaByScene = $this->copyVisualAssets($projectDir, $assets);

        foreach ($scenes as $index => $scene) {
            $sceneId = 'scene-'.($index + 1);
            $start = round((float) array_sum(array_column(array_slice($scenes, 0, $index), 'duration')), 3);
            $sceneDuration = max(0.5, (float) $scene['duration']);

            $sceneHosts[] = [
                'id' => $sceneId,
                'start' => $this->formatSeconds($start),
                'duration' => $this->formatSeconds($sceneDuration),
                'track' => $index + 1,
            ];

            $media = $mediaByScene[(int) ($scene['order'] ?? $index + 1)] ?? $mediaByScene[$index] ?? null;

            $sceneFiles[$sceneId] = view('hyperframes.scene', [
                'sceneId' => $sceneId,
                'width' => $width,
                'height' => $height,
                'safe' => $safe,
                'fontFamily' => $fontFamily,
                'isRtl' => $input['language'] === 'ar',
                'kicker' => $this->kicker($scene),
                'indexLabel' => str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).' / '.str_pad((string) count($scenes), 2, '0', STR_PAD_LEFT),
                'title' => (string) ($scene['headline'] ?? $scene['title'] ?? ''),
                'titleSize' => $this->titleSize((string) ($scene['headline'] ?? $scene['title'] ?? ''), $safe),
                'description' => (string) ($scene['description'] ?? ''),
                'highlight' => (string) ($scene['directives']['highlight_text'] ?? ''),
                'sfxCue' => (string) ($scene['directives']['sfx_cue'] ?? ''),
                'media' => $media,
                'motion' => $this->motion($sceneDuration),
            ])->render();
        }

        if (! empty($options['word_timings']) && is_array($options['word_timings'])) {
            $caption = $this->buildCaptionsFromTimings(
                $options['word_timings'],
                (bool) ($options['captions_verified'] ?? false),
                $duration,
                $width,
                $height,
                $safe,
                $fontFamily,
                $theme
            );
        } else {
            $caption = $this->buildCaptions($captionTrack, $duration, $width, $height, $safe, $fontFamily, $theme);
        }
        $captionHost = $caption['host'];
        $captionFile = $caption['html'];

        $audioClips = $this->copyAudioAssets($projectDir, $audio, $duration);

        $html = view('hyperframes.root', [
            'title' => $input['title'],
            'language' => $input['language'],
            'compositionId' => $compositionId,
            'width' => $width,
            'height' => $height,
            'fps' => $fps,
            'duration' => $this->formatSeconds($duration),
            'theme' => $theme,
            'accentRgb' => $this->hexToRgb($theme['accent']),
            'safe' => $safe,
            'fontFamily' => $fontFamily,
            'sceneHosts' => $sceneHosts,
            'captionHost' => $captionHost,
            'audioClips' => $audioClips,
        ])->render();

        $this->copyVendorGsap($projectDir);
        $this->writeProjectConfig($projectDir);

        File::put($projectDir.'/index.html', $html);

        foreach ($sceneFiles as $sceneId => $sceneHtml) {
            File::ensureDirectoryExists($projectDir.'/compositions');
            File::put($projectDir.'/compositions/'.$sceneId.'.html', $sceneHtml);
        }

        if ($captionFile !== null && $captionHost !== null) {
            File::put($projectDir.'/compositions/'.$captionHost['id'].'.html', $captionFile);
        }

        $manifest = [
            'version' => 1,
            'run_id' => $runId,
            'preset' => $preset,
            'width' => $width,
            'height' => $height,
            'fps' => $fps,
            'duration' => $duration,
            'theme' => (string) ($options['theme'] ?? 'cyber'),
            'language' => $input['language'],
            'scenes' => array_map(fn (array $scene): array => [
                'order' => (int) ($scene['order'] ?? 0),
                'type' => (string) ($scene['type'] ?? 'segment'),
                'duration' => (float) $scene['duration'],
                'headline' => (string) ($scene['headline'] ?? ''),
            ], $scenes),
            'assets' => array_map(
                fn (int $sceneIndex, array $asset): array => [
                    'scene_index' => $sceneIndex,
                    'src' => (string) $asset['src'],
                ],
                array_keys($mediaByScene),
                array_values($mediaByScene),
            ),
            'audio' => array_map(fn (array $clip): string => (string) $clip['src'], $audioClips),
            'captions' => $captionHost !== null ? 'included' : 'none',
        ];

        File::put($projectDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return [
            'html' => $html,
            'projectDir' => $projectDir,
            'compositionId' => $compositionId,
            'runId' => $runId,
            'manifest' => $manifest,
            'audioClips' => $audioClips,
        ];
    }

    /**
     * Keep long grounded headlines inside the safe vertical band instead of
     * letting a fixed display size overflow. Short headlines stay large.
     *
     * @param  array<string, mixed>  $safe
     */
    protected function titleSize(string $title, array $safe): int
    {
        $length = mb_strlen(trim($title));
        $factor = match (true) {
            $length > 100 => 0.55,
            $length > 80 => 0.63,
            $length > 60 => 0.72,
            $length > 40 => 0.84,
            default => 1.0,
        };

        return max((int) round($safe['body'] * 1.35), (int) round($safe['title'] * $factor));
    }

    /**
     * @param  array<int, mixed>  $scenes
     * @return list<array<string, mixed>>
     */
    protected function normalizeScenes(array $scenes, float $targetDuration): array
    {
        $normalized = [];

        foreach (array_values($scenes) as $index => $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $duration = $scene['duration'] ?? $scene['directives']['duration_seconds'] ?? null;
            $duration = is_numeric($duration) ? (float) $duration : null;

            $normalized[] = [
                ...$scene,
                'order' => (int) ($scene['order'] ?? $index + 1),
                'type' => (string) ($scene['type'] ?? 'segment'),
                'duration' => max(0.5, $duration ?? ($targetDuration > 0 ? $targetDuration / max(count($scenes), 1) : 3.0)),
            ];
        }

        if ($normalized === []) {
            $normalized[] = [
                'order' => 1,
                'type' => 'intro',
                'duration' => max(1.0, $targetDuration > 0 ? $targetDuration : 3.0),
                'headline' => 'AI-PVG',
                'description' => '',
            ];
        }

        usort($normalized, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_values($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    protected function safeZones(string $preset, int $width, int $height): array
    {
        $scale = max(0.6, $width / 1080);
        $vertical = $preset === '9:16';

        return [
            'top' => $vertical ? 168 : (int) round(96 * $scale),
            'bottom' => $vertical ? 590 : (int) round(140 * $scale),
            'side' => (int) round(($vertical ? 96 : 120) * $scale),
            'footer' => (int) round(24 * $scale),
            'kicker' => (int) round(30 * $scale),
            'title' => (int) round(($vertical ? 96 : 104) * $scale),
            'body' => (int) round(($vertical ? 40 : 42) * $scale),
            'highlight' => (int) round(34 * $scale),
            'credit' => (int) round(22 * $scale),
            'caption' => (int) round(38 * $scale),
            'caption_bottom' => $vertical ? 240 : (int) round(72 * $scale),
            'caption_width' => (int) round(($vertical ? 860 : 1200) * $scale),
            'caption_padding' => (int) round(18 * $scale),
            'text_width' => (int) round(($vertical ? 888 : 1400) * $scale),
            'media_height' => (int) round(($vertical ? 300 : 300) * $scale),
            'gap' => (int) round(34 * $scale),
            'radius' => (int) round(24 * $scale),
            'rule_width' => (int) round(140 * $scale),
            'rule_height' => max(4, (int) round(8 * $scale)),
        ];
    }

    /**
     * @return array<string, float>
     */
    protected function motion(float $duration): array
    {
        $exit = min(0.30, max(0.15, $duration * 0.08));
        $exitStart = max(0.8, $duration - $exit);
        $title = min(0.75, max(0.35, $duration * 0.18));

        return [
            'kicker' => round(min(0.5, $duration * 0.14), 3),
            'title' => round($title, 3),
            'rule' => round(min(0.55, $duration * 0.16), 3),
            'rule_start' => round(min(0.55, $duration * 0.16), 3),
            'body' => round(min(0.65, $duration * 0.20), 3),
            'body_start' => round(min(0.62, $duration * 0.19), 3),
            'highlight_start' => round(min(0.72, $duration * 0.22), 3),
            'media' => round(min(0.65, $duration * 0.20), 3),
            'media_start' => round(min(0.70, $duration * 0.22), 3),
            'drift' => round(max(0.1, $exitStart - 0.01), 3),
            'exit' => round($exit, 3),
            'exit_start' => round($exitStart, 3),
            'end' => round($duration, 3),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     * @return array<int, array{src: string, alt: string, credit: string}>
     */
    protected function copyVisualAssets(string $projectDir, array $assets): array
    {
        $media = [];

        foreach ($assets as $asset) {
            $source = (string) ($asset['source_path'] ?? '');
            if ($source === '' || ! is_file($source)) {
                continue;
            }

            $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION) ?: 'jpg');
            $name = 'scene-'.(int) $asset['scene_index'].'-'.(int) $asset['id'].'.'.$extension;
            $destination = $projectDir.'/assets/media/'.$name;
            File::ensureDirectoryExists(dirname($destination));

            if (! is_file($destination) || sha1_file($destination) !== sha1_file($source)) {
                File::copy($source, $destination);
            }

            $media[(int) $asset['scene_index']] ??= [
                'src' => 'assets/media/'.$name,
                'alt' => (string) ($asset['attribution'] ?? 'visual asset'),
                'credit' => (string) ($asset['attribution'] ?? ''),
            ];
        }

        ksort($media);

        return $media;
    }

    /**
     * @param  list<array<string, mixed>>  $audio
     * @return list<array{id: string, src: string, start: string, duration: string, volume: string, fade_in: string, fade_out: string, track: int}>
     */
    protected function copyAudioAssets(string $projectDir, array $audio, float $duration): array
    {
        $clips = [];
        $track = 90;

        foreach ($audio as $asset) {
            $source = (string) ($asset['source_path'] ?? '');
            if ($source === '' || ! is_file($source)) {
                continue;
            }

            $kind = preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string) ($asset['kind'] ?? 'audio'))) ?: 'audio';
            $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION) ?: 'mp3');
            $name = $kind.'-'.(int) $asset['id'].'.'.$extension;
            $destination = $projectDir.'/assets/audio/'.$name;
            File::ensureDirectoryExists(dirname($destination));

            if (! is_file($destination) || sha1_file($destination) !== sha1_file($source)) {
                File::copy($source, $destination);
            }

            $isVoice = str_contains($kind, 'voice') || str_contains($kind, 'narration');
            $isMix = $kind === 'mix';
            $volume = isset($asset['volume']) ? (string) $asset['volume'] : (($isVoice || $isMix) ? '1' : '0.12');
            $fadeIn = isset($asset['fade_in']) ? (string) $asset['fade_in'] : (($isVoice || $isMix) ? '0' : '0.3');
            $fadeOut = isset($asset['fade_out']) ? (string) $asset['fade_out'] : ($isMix ? '0' : '0.4');
            $startOffset = isset($asset['start']) ? $this->formatSeconds((float) $asset['start']) : '0';
            $clipDuration = isset($asset['duration']) ? $this->formatSeconds((float) $asset['duration']) : $this->formatSeconds($duration);

            $clips[] = [
                'id' => 'audio-'.$kind.'-'.(int) $asset['id'],
                'src' => 'assets/audio/'.$name,
                'start' => $startOffset,
                'duration' => $clipDuration,
                'volume' => $volume,
                'fade_in' => $fadeIn,
                'fade_out' => $fadeOut,
                'track' => $track++,
            ];
        }

        return $clips;
    }

    /**
     * @return array{host: ?array{id: string, start: string, duration: string, track: int}, html: ?string}
     */
    protected function buildCaptions(?CaptionTrack $track, float $duration, int $width, int $height, array $safe, string $fontFamily, array $theme): array
    {
        $timings = is_array($track?->word_timings) ? $track->word_timings : [];
        $words = [];

        foreach ($timings as $timing) {
            if (! is_array($timing) || ! isset($timing['word'], $timing['startMs'], $timing['endMs'])) {
                continue;
            }

            $start = max(0.0, ((float) $timing['startMs']) / 1000);
            $end = min($duration, ((float) $timing['endMs']) / 1000);

            if ($end <= $start) {
                continue;
            }

            $words[] = [
                'word' => (string) $timing['word'],
                'start' => round($start, 3),
                'end' => round($end, 3),
            ];
        }

        if ($words === []) {
            return ['host' => null, 'html' => null];
        }

        $groups = [];
        $chunk = array_slice($words, 0, 5);
        $groupIndex = 1;

        while ($chunk !== []) {
            $groupStart = (float) $chunk[0]['start'];
            $groupEnd = (float) end($chunk)['end'];
            $groupDuration = max(0.4, $groupEnd - $groupStart);
            $groupId = 'caption-'.$groupIndex;

            $groups[] = [
                'id' => $groupId,
                'start' => $this->formatSeconds($groupStart),
                'duration' => $this->formatSeconds(min($groupDuration, max(0.4, $duration - $groupStart))),
                'exit_start' => $this->formatSeconds(max($groupStart + 0.1, $groupEnd - 0.12)),
                'end' => $this->formatSeconds($groupEnd),
                'track' => 95,
                'words' => array_map(
                    fn (array $word, int $wordIndex): array => [
                        'id' => $groupId.'-w-'.$wordIndex,
                        'text' => $word['word'],
                        'start' => $this->formatSeconds((float) $word['start']),
                        'end' => $this->formatSeconds((float) $word['end']),
                    ],
                    $chunk,
                    array_keys($chunk),
                ),
            ];

            $words = array_slice($words, 5);
            $chunk = array_slice($words, 0, 5);
            $groupIndex++;
        }

        $captionId = 'captions';
        $html = view('hyperframes.captions', [
            'captionId' => $captionId,
            'width' => $width,
            'height' => $height,
            'safe' => $safe,
            'fontFamily' => $fontFamily,
            'theme' => $theme,
            'sourceLabel' => $track->isVerified() ? 'Verified transcript' : 'Estimated transcript',
            'groups' => $groups,
            'motion' => ['enter' => 0.18, 'exit' => 0.12],
        ])->render();

        return [
            'host' => [
                'id' => $captionId,
                'start' => '0',
                'duration' => $this->formatSeconds($duration),
                'track' => 95,
            ],
            'html' => $html,
        ];
    }

    /**
     * Compile word-level captions from plan timings into HTML.
     *
     * Generates a safe-zone bounded, word-pop caption track with staggered entrances,
     * two lines maximum, and a fade-out at the end of the video.
     *
     * @param  list<array{word: string, startMs: int, endMs: int}>  $wordTimings
     * @return array{host: ?array{id: string, start: string, duration: string, track: int}, html: ?string}
     */
    protected function buildCaptionsFromTimings(array $wordTimings, bool $verified, float $duration, int $width, int $height, array $safe, string $fontFamily, array $theme): array
    {
        $words = [];

        foreach ($wordTimings as $timing) {
            if (! is_array($timing) || ! isset($timing['word'], $timing['startMs'], $timing['endMs'])) {
                continue;
            }

            $start = max(0.0, ((float) $timing['startMs']) / 1000);
            $end = min($duration, ((float) $timing['endMs']) / 1000);

            if ($end <= $start) {
                continue;
            }

            $words[] = [
                'word' => (string) $timing['word'],
                'start' => round($start, 3),
                'end' => round($end, 3),
            ];
        }

        if ($words === []) {
            return ['host' => null, 'html' => null];
        }

        // Group words into chunks of 5, maintaining order
        $groups = [];
        $totalWords = count($words);
        $chunkSize = 5;
        $groupIndex = 1;

        for ($i = 0; $i < $totalWords; $i += $chunkSize) {
            $chunk = array_slice($words, $i, $chunkSize);
            $groupStart = (float) $chunk[0]['start'];
            $lastWord = end($chunk);
            $groupEnd = (float) $lastWord['end'];
            $groupDuration = max(0.4, $groupEnd - $groupStart);
            $groupId = 'caption-'.$groupIndex;

            // Build the word entries for this group
            $wordEntries = array_map(
                fn (array $word, int $wordIndex): array => [
                    'id' => $groupId.'-w-'.$wordIndex,
                    'text' => $word['word'],
                    'start' => $this->formatSeconds((float) $word['start']),
                    'end' => $this->formatSeconds((float) $word['end']),
                    'emphasis' => $wordIndex === 0 || $wordIndex === count($chunk) - 1,
                ],
                $chunk,
                array_keys($chunk),
            );

            $groups[] = [
                'id' => $groupId,
                'start' => $this->formatSeconds($groupStart),
                'duration' => $this->formatSeconds(min($groupDuration, max(0.4, $duration - $groupStart))),
                'exit_start' => $this->formatSeconds(max($groupStart + 0.1, $groupEnd - 0.12)),
                'end' => $this->formatSeconds($groupEnd),
                'track' => 95,
                'words' => $wordEntries,
            ];

            $groupIndex++;
        }

        $captionId = 'captions';
        $html = view('hyperframes.captions', [
            'captionId' => $captionId,
            'width' => $width,
            'height' => $height,
            'safe' => $safe,
            'fontFamily' => $fontFamily,
            'theme' => $theme,
            'sourceLabel' => $verified ? 'Verified transcript' : 'Estimated transcript',
            'groups' => $groups,
            'motion' => ['enter' => 0.18, 'exit' => 0.12],
        ])->render();

        return [
            'host' => [
                'id' => $captionId,
                'start' => '0',
                'duration' => $this->formatSeconds($duration),
                'track' => 95,
            ],
            'html' => $html,
        ];
    }

    public function parsePreset(string $preset): array
    {
        return match ($preset) {
            '16:9' => [1920, 1080],
            '1:1' => [1080, 1080],
            default => [1080, 1920],
        };
    }

    protected function getTheme(string $name): array
    {
        $themes = [
            'breaking' => ['bg' => '#05070a', 'ink' => '#ffffff', 'accent' => '#ff4d6d', 'glow' => '#ff8fa3', 'panel' => 'rgba(255,255,255,0.08)', 'textDim' => 'rgba(255,255,255,0.86)'],
            'minimal' => ['bg' => '#f8fafc', 'ink' => '#0f172a', 'accent' => '#0284c7', 'glow' => '#38bdf8', 'panel' => 'rgba(15,23,42,0.05)', 'textDim' => 'rgba(15,23,42,0.78)'],
            'cyber' => ['bg' => '#0a0c12', 'ink' => '#ffffff', 'accent' => '#00d4ff', 'glow' => '#00f0ff', 'panel' => 'rgba(255,255,255,0.08)', 'textDim' => 'rgba(255,255,255,0.86)'],
        ];

        return $themes[$name] ?? $themes['cyber'];
    }

    protected function formatSeconds(float $seconds): string
    {
        $formatted = rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    protected function sanitizeRunId(string $runId): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $runId) ?: 'run';

        return trim($clean, '-.');
    }

    protected function prepareOutput(string $runId): string
    {
        $projectDir = HyperFramesRuntime::join($this->outputRoot, $runId);
        File::ensureDirectoryExists($projectDir.'/compositions');
        File::ensureDirectoryExists($projectDir.'/assets/vendor');
        File::ensureDirectoryExists($projectDir.'/assets/media');
        File::ensureDirectoryExists($projectDir.'/assets/audio');

        return $projectDir;
    }

    protected function copyVendorGsap(string $projectDir): void
    {
        $candidates = [
            HyperFramesRuntime::join($this->templateDir, 'assets/vendor/gsap.min.js'),
            HyperFramesRuntime::join($this->templateDir, 'node_modules/gsap/dist/gsap.min.js'),
        ];
        foreach ($candidates as $source) {
            if (is_file($source)) {
                $destination = $projectDir.'/assets/vendor/gsap.min.js';
                if (! is_file($destination) || sha1_file($destination) !== sha1_file($source)) {
                    File::copy($source, $destination);
                }

                return;
            }
        }
        throw new RuntimeException('Vendored GSAP missing; run npm install in hyperframes/');
    }

    protected function writeProjectConfig(string $projectDir): void
    {
        File::put($projectDir.'/hyperframes.json', json_encode([
            'paths' => ['blocks' => 'compositions', 'components' => 'compositions/components', 'assets' => 'assets'],
            'media' => ['autoProxy' => true],
        ], JSON_PRETTY_PRINT));
    }

    protected function kicker(array $scene): string
    {
        $type = (string) ($scene['type'] ?? 'segment');

        return strtoupper(str_replace(['_', '-'], ' ', $type));
    }

    protected function hexToRgb(string $hex): string
    {
        if (preg_match('/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i', $hex, $matches) !== 1) {
            return '0, 212, 255';
        }

        return ((int) hexdec($matches[1])).', '.((int) hexdec($matches[2])).', '.((int) hexdec($matches[3]));
    }

    /**
     * @return list<array{id: int, scene_index: int, source_path: string, attribution: string}>
     */
    protected function resolveAssets(VideoScript $script): array
    {
        if (! class_exists(SceneAsset::class) || ! Schema::hasTable('scene_assets')) {
            return [];
        }

        return SceneAsset::query()
            ->where('video_script_id', $script->getKey())
            ->with('visualAsset')
            ->get()
            ->filter(fn (SceneAsset $sa) => $sa->visualAsset !== null)
            ->map(function (SceneAsset $sceneAsset): array {
                $visual = $sceneAsset->visualAsset;
                $storagePath = (string) $visual->storage_path;
                $fullPath = is_file(storage_path('app/public/'.$storagePath))
                    ? storage_path('app/public/'.$storagePath)
                    : (is_file(storage_path('app/'.$storagePath)) ? storage_path('app/'.$storagePath) : $storagePath);

                return [
                    'id' => (int) $visual->getKey(),
                    'scene_index' => (int) $sceneAsset->scene_index,
                    'source_path' => $fullPath,
                    'attribution' => (string) ($visual->attribution_text ?? $sceneAsset->attribution_text ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, kind: string, source_path: string}>
     */
    protected function resolveAudio(VideoScript $script): array
    {
        if (! class_exists(AudioAsset::class) || ! Schema::hasTable('audio_assets')) {
            return [];
        }

        $all = AudioAsset::query()
            ->where('video_script_id', $script->getKey())
            ->orderBy('id')
            ->get();

        $resolved = [];
        $hasVoice = false;
        $hasMusic = false;

        foreach ($all as $audio) {
            $storagePath = (string) $audio->storage_path;
            $fullPath = is_file(storage_path('app/public/'.$storagePath))
                ? storage_path('app/public/'.$storagePath)
                : (is_file(storage_path('app/'.$storagePath)) ? storage_path('app/'.$storagePath) : $storagePath);

            if (! is_file($fullPath)) {
                continue;
            }

            $kind = strtolower((string) ($audio->kind ?? 'narration'));
            $isVoice = str_contains($kind, 'voice') || str_contains($kind, 'narration');
            $isMusic = str_contains($kind, 'music') || str_contains($kind, 'background');

            if ($isVoice && ! $hasVoice) {
                $hasVoice = true;
                $resolved[] = [
                    'id' => (int) $audio->getKey(),
                    'kind' => $kind,
                    'source_path' => $fullPath,
                ];
            } elseif ($isMusic && ! $hasMusic) {
                $hasMusic = true;
                $resolved[] = [
                    'id' => (int) $audio->getKey(),
                    'kind' => $kind,
                    'source_path' => $fullPath,
                ];
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function resolveCaptionTrack(VideoScript $script, array $options = []): ?CaptionTrack
    {
        if (isset($options['caption_track']) && $options['caption_track'] instanceof CaptionTrack) {
            return $options['caption_track'];
        }

        if (! class_exists(CaptionTrack::class) || ! Schema::hasTable('caption_tracks')) {
            return null;
        }

        return CaptionTrack::query()
            ->where('video_script_id', $script->getKey())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{html: string, projectDir: string, compositionId: string, runId: string, manifest: array<string, mixed>}
     */
    public function writeAgentComposition(VideoScript $script, string $html, array $options = []): array
    {
        $runId = $this->sanitizeRunId((string) ($options['run_id'] ?? ('agent-script-'.$script->getKey())));
        $projectDir = $this->prepareOutput($runId);
        $this->copyVendorGsap($projectDir);
        $this->writeProjectConfig($projectDir);

        File::put($projectDir.'/index.html', $html);

        $manifest = [
            'compositionId' => 'agent',
            'runId' => $runId,
            'mode' => 'agent',
            'title' => (string) $script->title,
            'scenes' => [],
            'media' => [],
            'audio' => [],
            'captions' => 'none',
        ];

        File::put($projectDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return [
            'html' => $html,
            'projectDir' => $projectDir,
            'compositionId' => 'agent',
            'runId' => $runId,
            'manifest' => $manifest,
        ];
    }
}
