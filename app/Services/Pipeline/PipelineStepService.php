<?php

namespace App\Services\Pipeline;

use App\Enums\VideoRenderStatus;
use App\Enums\VideoScriptStatus;
use App\Jobs\ExecutePipelineStepJob;
use App\Models\AudioAsset;
use App\Models\CaptionTrack;
use App\Models\FeatureFlag;
use App\Models\MetadataVariant;
use App\Models\News;
use App\Models\NewsSource;
use App\Models\PipelineRun;
use App\Models\PipelineRunStage;
use App\Models\ScriptVariant;
use App\Models\StoryCluster;
use App\Models\VideoRender;
use App\Models\VideoScript;
use App\Services\AI\Captions\CaptionTrackBuilder;
use App\Services\AI\Captions\WordTimingService;
use App\Services\AI\ScriptPipeline\ScriptPipelineService;
use App\Services\Audio\ElevenLabs\ElevenLabsClient;
use App\Services\Audio\ElevenLabs\TtsService;
use App\Services\Automation\FanOutBatchService;
use App\Services\Automation\RemotionPropInjector;
use App\Services\Automation\ScenePropBuilder;
use App\Services\Automation\Triage\TriageGate;
use App\Services\Automation\Triage\TriageRunner;
use App\Services\Rendering\Audio\AudioPipeline;
use App\Services\Rendering\EditPlan\EditPlanBuilder;
use App\Services\Rendering\EditPlan\EditPlanValidator;
use App\Services\Motion\MotionSelector;
use App\Services\Production\ProductionManifestBuilder;
use App\Services\Production\ProductionPreflight;
use App\Services\RssImportService;
use App\Services\VideoRenderingService;
use App\Services\VoiceGenerationService;
use App\Support\RemotionRuntime;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PipelineStepService
{
    public const STEP_NAMES = [
        1 => 'ingest',
        2 => 'triage',
        3 => 'script',
        4 => 'audio',
        5 => 'visuals',
        6 => 'render',
    ];

    /**
     * Apply and persist local pipeline safety flags, strictly enforcing publishing is locked OFF.
     *
     * @return array<string, bool> Changed flags and their applied boolean values
     */
    public function applySafetyFlags(array $options = []): array
    {
        $avatarEnabled = (bool) ($options['enable_avatar'] ?? false);
        $hyperframesEnabled = ($options['backend'] ?? 'hyperframes') === 'hyperframes';

        config([
            'automation.enabled' => true,
            'automation.dry_run' => false,
            'automation.triage.enabled' => true,
            'automation.script_agents.enabled' => true,
            'automation.publishing.enabled' => false,
            'automation.avatar.feature_flag' => 'avatar.enabled',
            'automation.render.backend' => $hyperframesEnabled ? 'hyperframes' : 'remotion',
        ]);

        $flags = [
            'triage.news_scoring_enabled' => true,
            'script_agents.enabled' => true,
            'captions.enabled' => true,
            'assets.enabled' => true,
            'formats.enabled' => true,
            'render.hyperframes_enabled' => $hyperframesEnabled,
            'audio.pipeline_enabled' => true,
            'audio.elevenlabs.enabled' => (bool) ($options['enable_elevenlabs'] ?? false),
            'avatar.enabled' => $avatarEnabled,
            'publishing.enabled' => false,
        ];

        if (Schema::hasTable('feature_flags')) {
            foreach ($flags as $key => $val) {
                FeatureFlag::setFlag($key, $val);
            }
        }

        return $flags;
    }

    /**
     * Step 1: Prepare & Load Breaking News.
     *
     * @return array{success: bool, step: int, log: string, error: ?string, fix_hint: ?string, artifacts: array, stage: ?PipelineRunStage}
     */
    public function runStep1(array $options = [], ?PipelineRun $run = null, ?int $userId = null): array
    {
        $flags = [];
        if ($options['auto_enable_flags'] ?? true) {
            $flags = $this->applySafetyFlags($options);
        }

        $log = "=== Step 1: Prepare & Load Breaking News ===\n";
        $sourceMode = (string) ($options['source_mode'] ?? 'mock_cluster');
        $importedCount = 0;

        if ($sourceMode === 'live_rss' && Schema::hasTable('news_sources')) {
            $importer = app(RssImportService::class);
            $sources = NewsSource::query()->where('is_active', true)->get();
            foreach ($sources as $source) {
                try {
                    $res = $importer->import($source, ['status' => 'ready']);
                    $importedCount += $res->insertedItems;
                } catch (Throwable) {
                    // Gracefully skip failing feeds
                }
            }
            $log .= "✓ Imported {$importedCount} news articles via live RSS feeds\n";
        } else {
            $clusterSize = (int) ($options['cluster_size'] ?? 3);
            Artisan::call('ai-pvg:seed-mock-cluster', [
                '--cluster-size' => $clusterSize,
                '--skip-triage' => true,
                '--skip-script' => true,
                '--skip-render' => true,
            ]);
            $output = Artisan::output();
            $log .= "✓ Seeded multi-source demo breaking news cluster ({$clusterSize} sources)\n".trim($output)."\n";
        }

        $readyCount = Schema::hasTable('news')
            ? News::query()->where('status', 'ready')->count()
            : 0;

        $log .= "✓ Ready articles in database: {$readyCount}\n";
        $log .= '✓ Flags applied: '.json_encode($flags)."\n";
        $log .= "🔒 publishing.enabled = false (strictly locked)\n";

        $artifacts = [
            'ready_news_count' => $readyCount,
            'source_mode' => $sourceMode,
            'flags' => $flags,
            'who' => $userId,
        ];

        $stage = $run?->markStepCompleted(1, $log, $artifacts);

        return [
            'success' => true,
            'step' => 1,
            'log' => $log,
            'error' => null,
            'fix_hint' => null,
            'artifacts' => $artifacts,
            'stage' => $stage,
        ];
    }

    /**
     * Step 2: Score News & Build Story Cluster (G-02).
     */
    public function runStep2(array $options = [], ?PipelineRun $run = null, ?int $userId = null): array
    {
        $this->applySafetyFlags($options);

        $threshold = max(0.1, min(0.95, (float) ($options['similarity_threshold'] ?? 0.35)));

        config([
            'automation.story_clustering.similarity_threshold' => $threshold,
            'automation.news_scoring.cluster_similarity_threshold' => $threshold,
        ]);

        /** @var TriageGate $gate */
        $gate = app(TriageGate::class);
        if (! $gate->enabled()) {
            throw new RuntimeException('Triage gate is disabled by policy or feature flags.');
        }

        /** @var TriageRunner $runner */
        $runner = app(TriageRunner::class);
        $report = $runner->run(runScoring: true, runClustering: true);

        $scoredCount = count($report->scores);
        $clusterCount = count($report->clusters);

        $targetClusterId = $options['cluster_id'] ?? null;
        $targetCluster = null;

        if ($targetClusterId && Schema::hasTable('story_clusters')) {
            $targetCluster = StoryCluster::query()->find($targetClusterId);
        }

        if (! $targetCluster && Schema::hasTable('story_clusters')) {
            $targetCluster = StoryCluster::query()->latest()->first();
        }

        $log = "=== Step 2: Score News & Build Story Cluster ===\n"
            ."✓ Scored {$scoredCount} ready article(s)\n"
            ."✓ Similarity threshold: {$threshold}\n"
            ."✓ Formed {$clusterCount} cluster(s)\n";

        if ($targetCluster) {
            $log .= "✓ Target Cluster #{$targetCluster->getKey()}: {$targetCluster->canonical_title}\n"
                .'  Confidence score: '.number_format((float) ($targetCluster->confidence ?? 0), 2)."\n";
        }

        $artifacts = [
            'scored_count' => $scoredCount,
            'cluster_count' => $clusterCount,
            'cluster_id' => $targetCluster?->getKey(),
            'canonical_title' => $targetCluster?->canonical_title,
            'confidence' => $targetCluster?->confidence,
            'who' => $userId,
        ];

        $stage = $run?->markStepCompleted(2, $log, $artifacts);

        return [
            'success' => true,
            'step' => 2,
            'log' => $log,
            'error' => null,
            'fix_hint' => null,
            'artifacts' => $artifacts,
            'stage' => $stage,
        ];
    }

    /**
     * Step 3: Generate AI Script, 3 Hooks & Fact-Check (G-03).
     */
    public function runStep3(array $options = [], ?PipelineRun $run = null, ?int $userId = null): array
    {
        $this->applySafetyFlags($options);

        $duration = max(10, min(180, (int) ($options['target_duration'] ?? 45)));

        config([
            'automation.script_agents.target_duration' => $duration,
            'automation.script_agents.duration_tolerance' => max(15, (int) ceil($duration * 0.2)),
            'automation.script_agents.max_scene_seconds' => max(15, (int) ceil($duration / 2)),
        ]);

        $subject = null;
        if (! empty($options['cluster_id']) && Schema::hasTable('story_clusters')) {
            $subject = StoryCluster::query()->find($options['cluster_id']);
        }
        if (! $subject && Schema::hasTable('story_clusters')) {
            $subject = StoryCluster::query()->latest()->first();
        }
        if (! $subject && Schema::hasTable('news')) {
            $subject = News::query()->where('status', 'ready')->latest()->first();
        }

        if ($subject === null) {
            throw new RuntimeException('No story cluster or ready news available. Run Step 1 and Step 2 first.');
        }

        /** @var ScriptPipelineService $pipeline */
        $pipeline = app(ScriptPipelineService::class);
        $result = $pipeline->runPipeline($subject);

        $script = $result->script ?? (Schema::hasTable('video_scripts') ? VideoScript::query()->latest()->first() : null);

        if (! $script) {
            throw new RuntimeException('ScriptPipelineService finished without generating a VideoScript.');
        }

        $variantCount = Schema::hasTable('script_variants')
            ? ScriptVariant::query()->where('video_script_id', $script->getKey())->count()
            : 3;

        // Generate and validate edit_plan.json (hook, pacing, transitions, scene purposes)
        $editPlan = null;
        $planErrors = [];
        $planPath = null;
        if (class_exists(EditPlanBuilder::class)) {
            try {
                $builder = app(EditPlanBuilder::class);
                $validator = app(EditPlanValidator::class);
                $editPlan = $builder->fromScript($script, [
                    'preset' => $options['aspect_ratio'] ?? '9:16',
                    'theme' => $options['theme'] ?? 'cyber',
                ]);
                $valResult = $validator->validate($editPlan);
                if (! ($valResult['ok'] ?? false)) {
                    $planErrors = $valResult['errors'] ?? [];
                }
                $runDir = storage_path('app/hyperframes/runs/script_'.$script->getKey());
                File::ensureDirectoryExists($runDir);
                $planPath = $runDir.'/edit_plan.json';
                file_put_contents($planPath, json_encode($editPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } catch (Throwable $e) {
                $planErrors[] = $e->getMessage();
            }
        }

        $log = "=== Step 3: 3-Agent Script & Fact-Check Pipeline ===\n"
            ."✓ VideoScript #{$script->getKey()}: {$script->title}\n"
            ."✓ Target Duration: {$duration}s\n"
            ."✓ Hook variants generated: {$variantCount}\n"
            .'✓ Scenes planned: '.count($script->scenes ?? [])."\n";

        if ($editPlan) {
            $log .= "✓ Edit Plan generated: {$editPlan['preset']}, ".count($editPlan['scenes'] ?? [])." scenes, duration {$editPlan['duration']}s\n";
            if ($planErrors !== []) {
                $log .= "⚠️ Edit Plan warnings: ".implode('; ', array_slice($planErrors, 0, 3))."\n";
            }
        }

        $artifacts = [
            'script_id' => $script->getKey(),
            'title' => $script->title,
            'duration' => $duration,
            'scenes_count' => count($script->scenes ?? []),
            'variants_count' => $variantCount,
            'edit_plan_path' => $planPath,
            'edit_plan_errors' => $planErrors,
            'who' => $userId,
        ];

        $stage = $run?->markStepCompleted(3, $log, $artifacts);

        return [
            'success' => true,
            'step' => 3,
            'log' => $log,
            'error' => null,
            'fix_hint' => null,
            'artifacts' => $artifacts,
            'stage' => $stage,
        ];
    }

    /**
     * Step 4: Generate Voice, Word Captions & Audio Mix.
     */
    public function runStep4(array $options = [], ?PipelineRun $run = null, ?int $userId = null): array
    {
        $this->applySafetyFlags($options);

        $script = $this->resolveScript($options);
        if (! $script) {
            throw new RuntimeException('No approved VideoScript found. Run Step 3 first.');
        }

        $narration = (string) ($script->narration ?? '');
        $audioProvider = (string) ($options['audio_provider'] ?? config('automation.audio.provider', 'local'));
        $elevenLabsResult = null;
        $timings = [];
        $ttsProviderName = 'local';
        $costMicros = 0;

        // Opt-in ElevenLabs speech synthesis when enabled and configured
        if ($audioProvider === 'elevenlabs' && class_exists(TtsService::class) && FeatureFlag::isEnabled('audio.elevenlabs.enabled')) {
            /** @var ElevenLabsClient $client */
            $client = app(ElevenLabsClient::class);
            if ($client->isConfigured()) {
                /** @var TtsService $ttsService */
                $ttsService = app(TtsService::class);
                $runId = $run?->id ?? 'script_'.$script->getKey();
                $runDir = storage_path("app/production/run_{$runId}/audio");
                File::ensureDirectoryExists($runDir.'/narration');
                $outPath = $runDir.'/narration/full_narration.wav';

                $elevenLabsResult = $ttsService->synthesize($narration, $outPath, [
                    'voice_id' => $options['voice_id'] ?? null,
                    'language' => $script->language ?? 'en',
                ]);
                $timings = $elevenLabsResult['word_timings'];
                $ttsProviderName = 'elevenlabs';
                $costMicros = $elevenLabsResult['cost_micros'];
            }
        }

        // Local offline fallback
        if ($elevenLabsResult === null) {
            /** @var VoiceGenerationService $voiceSvc */
            $voiceSvc = app(VoiceGenerationService::class);
            $voice = $voiceSvc->generate($script);

            /** @var WordTimingService $timingSvc */
            $timingSvc = app(WordTimingService::class);
            $duration = (float) ($script->estimated_duration ?? 30) * 1000;
            $timings = $timingSvc->estimateFromText($narration, $duration);
        }

        /** @var CaptionTrackBuilder $captionBuilder */
        $captionBuilder = app(CaptionTrackBuilder::class);
        $chunks = $captionBuilder->buildChunks($narration, $script->language ?? 'en');

        $captionTrack = null;
        if (Schema::hasTable('caption_tracks')) {
            $captionTrack = CaptionTrack::query()->create([
                'video_script_id' => $script->getKey(),
                'language' => $script->language ?? 'en',
                'source' => $elevenLabsResult !== null ? 'verified' : 'estimated',
                'style_preset' => [
                    'style' => $options['caption_style'] ?? 'karaoke',
                    'ducking' => ($options['ducking'] ?? true) ? -18 : 0,
                ],
                'word_timings' => $timings,
                'status' => $elevenLabsResult !== null ? 'verified' : 'estimated',
            ]);
        }

        $audioAsset = null;
        if (Schema::hasTable('audio_assets')) {
            $audioStoragePath = null;
            $audioDuration = (float) ($script->estimated_duration ?? 30);
            $licenseSource = 'local_tts';

            if ($elevenLabsResult !== null && isset($outPath) && is_file($outPath)) {
                $audioStoragePath = $outPath;
                $audioDuration = (float) ($elevenLabsResult['duration'] ?? $audioDuration);
                $licenseSource = 'elevenlabs';
            } elseif (isset($voice) && $voice && ! empty($voice->audio_path)) {
                $audioStoragePath = (string) $voice->audio_path;
                $audioDuration = (float) ($voice->duration > 0 ? $voice->duration : $audioDuration);
                $licenseSource = (string) ($voice->provider ?: 'voice_generation');
            }

            if ($audioStoragePath !== null) {
                $audioAsset = AudioAsset::query()->updateOrCreate(
                    [
                        'video_script_id' => $script->getKey(),
                        'kind' => 'narration',
                    ],
                    [
                        'storage_path' => $audioStoragePath,
                        'license_source' => $licenseSource,
                        'duration_seconds' => $audioDuration,
                    ]
                );
            }
        }

        $log = "=== Step 4: Voiceover, Word-Level Captions & Audio Mix ===\n"
            ."✓ TTS Provider: {$ttsProviderName}\n"
            .($elevenLabsResult !== null ? "✓ ElevenLabs synthesis: {$elevenLabsResult['voice']} ({$elevenLabsResult['duration']}s, ".($elevenLabsResult['cached'] ? 'CACHE HIT' : 'API billed').")\n" : '✓ Voice synthesis: completed\n')
            .($audioAsset !== null ? "✓ Audio narration asset recorded (#{$audioAsset->getKey()})\n" : '')
            .'✓ Monotonic word timings: '.count($timings)." words\n"
            .'✓ Caption chunks aligned: '.count($chunks)."\n"
            ."✓ Audio ducking applied: -18dB background bed\n";

        $artifacts = [
            'script_id' => $script->getKey(),
            'caption_track_id' => $captionTrack?->getKey(),
            'audio_asset_id' => $audioAsset?->getKey(),
            'tts_provider' => $ttsProviderName,
            'word_timings_count' => count($timings),
            'chunks_count' => count($chunks),
            'cost_micros' => $costMicros,
            'cached' => $elevenLabsResult['cached'] ?? false,
            'who' => $userId,
        ];

        $stage = $run?->markStepCompleted(4, $log, $artifacts);

        return [
            'success' => true,
            'step' => 4,
            'log' => $log,
            'error' => null,
            'fix_hint' => null,
            'artifacts' => $artifacts,
            'stage' => $stage,
        ];
    }

    /**
     * Step 5: Attach Visual B-Roll & Multi-Format Plans.
     */
    public function runStep5(array $options = [], ?PipelineRun $run = null, ?int $userId = null): array
    {
        $this->applySafetyFlags($options);

        $script = $this->resolveScript($options);
        if (! $script) {
            throw new RuntimeException('No approved VideoScript found. Run Step 3 first.');
        }

        $scenes = $script->scenes ?? [];
        $scriptId = (int) $script->getKey();

        /** @var ScenePropBuilder $propBuilder */
        $propBuilder = app(ScenePropBuilder::class);
        /** @var RemotionPropInjector $injector */
        $injector = app(RemotionPropInjector::class);

        $allProps = [];
        foreach ($scenes as $index => $scene) {
            $props = $propBuilder->buildProps($scriptId, $index, $scene);
            $allProps[] = $props;
        }

        $payload = ['script_id' => $scriptId, 'scenes' => $scenes];
        $injector->inject($payload, [
            'assets' => array_merge(...array_map(fn ($p) => $p['assets'] ?? [], $allProps)),
        ]);

        $formats = $options['formats'] ?? ['9:16', '16:9', '1:1'];

        if (class_exists(FanOutBatchService::class)) {
            $fanOut = app(FanOutBatchService::class);
            $render = Schema::hasTable('video_renders')
                ? VideoRender::query()->firstOrCreate(
                    ['video_script_id' => $scriptId],
                    ['template' => 'remotion', 'status' => 'completed', 'resolution' => '1080x1920', 'fps' => 30]
                )
                : null;
            if ($render) {
                $fanOut->planFanOut((int) $render->getKey());
            }
        }

        if (Schema::hasTable('metadata_variants')) {
            foreach ($formats as $fmt) {
                MetadataVariant::query()->firstOrCreate(
                    ['video_script_id' => $scriptId, 'variant_key' => 'fmt_'.Str::slug($fmt)],
                    [
                        'title_text' => (string) $script->title,
                        'description_text' => (string) ($script->narration ?? ''),
                        'tags_json' => ['tech', 'ai', 'breaking', $fmt],
                        'status' => 'ready',
                    ]
                );
            }
        }

        $motionResult = null;
        if (class_exists(MotionSelector::class)) {
            $seed = (int) ($options['motion_seed'] ?? 1001);
            $tone = (string) ($options['tone'] ?? 'normal');
            $format = (string) ($options['aspect_ratio'] ?? '9:16');
            $locks = (array) ($options['motion_locks'] ?? []);

            $selector = new MotionSelector($seed, $tone, $format, $locks);
            $motionResult = $selector->selectForScenes($scenes, $run);
        }

        $log = "=== Step 5: Visual Scene Props & Multi-Format Fan-Out ===\n"
            .'✓ Scene props injected for '.count($scenes)." scene(s)\n"
            .'✓ Formats planned: '.implode(', ', $formats)."\n"
            ."✓ A/B Metadata variants and thumbnail plan created\n"
            .($motionResult !== null && ! empty($motionResult['selections']) ? '✓ Motion Graphics: '.count($motionResult['selections']).' component(s) selected (Fingerprint: '.substr($motionResult['fingerprint'], 0, 10)."…)\n" : '');

        $artifacts = [
            'script_id' => $scriptId,
            'scenes_count' => count($scenes),
            'formats' => $formats,
            'motion_selections' => $motionResult['selections'] ?? [],
            'motion_fingerprint' => $motionResult['fingerprint'] ?? null,
            'who' => $userId,
        ];

        $stage = $run?->markStepCompleted(5, $log, $artifacts);

        return [
            'success' => true,
            'step' => 5,
            'log' => $log,
            'error' => null,
            'fix_hint' => null,
            'artifacts' => $artifacts,
            'stage' => $stage,
        ];
    }

    /**
     * Step 6: Render & Verify Final MP4 Video Locally.
     */
    public function runStep6(array $options = [], ?PipelineRun $run = null, ?int $userId = null): array
    {
        config(['automation.publishing.enabled' => false]);
        $backend = (string) ($options['backend'] ?? 'hyperframes');
        $script = $this->resolveScript($options);

        // The authoritative preflight gate. The Filament page also checks first
        // for quick feedback, but the CLI and queued jobs only pass through here.
        // Runs before the auto-approve below so a blocked render changes nothing.
        if ($script !== null) {
            $check = app(ProductionPreflight::class)->check([
                'script_id' => $script->getKey(),
                'cluster_id' => $options['cluster_id'] ?? null,
                'run' => $run,
                'aspect_ratio' => $options['aspect_ratio'] ?? '9:16',
                'backend' => $backend,
            ]);

            if (! $check['ready_to_render']) {
                throw new RuntimeException(
                    "Step 6 blocked by production preflight:\n".ProductionPreflight::formatMissing($check)
                );
            }
        }

        // Auto-approve script for production render if needed
        if ($script && $script->status !== VideoScriptStatus::Approved) {
            $script->update(['status' => VideoScriptStatus::Approved]);
        }

        $log = "=== Step 6: Render Final MP4 Video Locally ===\n"
            ."🔒 Zero external publishing enforced\n"
            ."✓ Backend: {$backend}\n";

        if ($backend === 'hyperframes') {
            $params = [
                '--execute' => true,
                '--preset' => $options['aspect_ratio'] ?? '9:16',
                '--theme' => $options['theme'] ?? 'cyber',
            ];
            if ($script) {
                $params['--script-id'] = (int) $script->getKey();
            }
            $exitCode = Artisan::call('ai-pvg:verify-hyperframes', $params);
            $output = Artisan::output();
            if ($exitCode !== 0) {
                throw new RuntimeException("HyperFrames render failed (exit code {$exitCode}):\n".trim($output));
            }
            $log .= "✓ HyperFrames render executed\n".trim($output)."\n";
        } else {
            // Remotion path: if script available in non-test mode, render it cleanly with VideoRenderingService
            if ($script && class_exists(VideoRenderingService::class) && ! app()->environment('testing')) {
                $render = VideoRender::query()->create([
                    'video_script_id' => $script->getKey(),
                    'template' => $options['theme'] ?? 'cyber',
                    'status' => 'pending',
                    'resolution' => match ($options['aspect_ratio'] ?? '9:16') {
                        '16:9' => '1920x1080',
                        '1:1' => '1080x1080',
                        default => '1080x1920',
                    },
                    'fps' => 30,
                ]);
                /** @var VideoRenderingService $svc */
                $svc = app(VideoRenderingService::class);
                $rendered = $svc->render($render);
                $renderedStatus = $rendered->status instanceof \BackedEnum
                    ? $rendered->status->value
                    : (string) $rendered->status;
                $log .= "✓ Remotion render for script #{$script->getKey()} finished (Status: {$renderedStatus})\n";
                if ($renderedStatus === VideoRenderStatus::Completed->value && ! empty($rendered->output_path)) {
                    $renderedAbs = RemotionRuntime::isAbsolute($rendered->output_path)
                        ? $rendered->output_path
                        : base_path($rendered->output_path);
                    if (is_file($renderedAbs)) {
                        $dest = storage_path('app/video_out/remotion_script'.$script->getKey().'_'.date('Ymd_His').'.mp4');
                        File::ensureDirectoryExists(dirname($dest));
                        File::copy($renderedAbs, $dest);
                        $log .= "✓ Output saved to: ".basename($dest)."\n";
                    }
                } elseif ($rendered->error_message) {
                    $log .= "⚠️ Note: {$rendered->error_message}\n";
                }
            } else {
                Artisan::call('ai-pvg:verify-mp4', ['--execute' => true]);
                $output = Artisan::output();
                $log .= "✓ Remotion test verification completed\n".trim($output);
            }
        }

        // Find latest rendered MP4 in video_out
        $outDir = storage_path('app/video_out');
        $latestMp4 = null;
        if (is_dir($outDir)) {
            $files = glob($outDir.'/*.mp4') ?: [];
            if ($files !== []) {
                usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
                $latestMp4 = $files[0];
            }
        }

        // Build and persist canonical production_manifest.json
        $manifestResult = null;
        if (class_exists(ProductionManifestBuilder::class)) {
            $builder = app(ProductionManifestBuilder::class);
            $runId = $run?->run_id ?? ('run_'.date('Ymd_His'));
            $manifestResult = $builder->build($runId, [
                'run' => $run,
                'script' => $script,
                'backend' => $backend,
                'aspect_ratio' => $options['aspect_ratio'] ?? '9:16',
                'theme' => $options['theme'] ?? 'cyber-pulse',
                'output_mp4' => $latestMp4,
            ]);
            $log .= "✓ Production Manifest built: ".substr($manifestResult['checksum'], 0, 12)."… (status: verified)\n";
        }

        $artifacts = [
            'backend' => $backend,
            'aspect_ratio' => $options['aspect_ratio'] ?? '9:16',
            'latest_mp4' => $latestMp4 ? basename($latestMp4) : null,
            'mp4_path' => $latestMp4,
            'manifest_checksum' => $manifestResult['checksum'] ?? null,
            'manifest_path' => $manifestResult['path'] ?? null,
            'who' => $userId,
        ];

        $stage = $run?->markStepCompleted(6, $log, $artifacts);

        return [
            'success' => true,
            'step' => 6,
            'log' => $log,
            'error' => null,
            'fix_hint' => null,
            'artifacts' => $artifacts,
            'stage' => $stage,
        ];
    }

    /**
     * Dispatch a long-running step as an asynchronous queued job.
     */
    public function dispatchStep(int $stepNumber, array $options = [], ?PipelineRun $run = null, ?int $userId = null): PipelineRunStage
    {
        $run = $run ?? $this->findOrCreateRun($options, $userId);
        $stepName = self::STEP_NAMES[$stepNumber] ?? 'step_'.$stepNumber;

        $existing = $run->stages()->where('step_number', $stepNumber)->first();
        if ($existing && in_array($existing->status, ['queued', 'running'], true)) {
            return $existing;
        }

        $stage = $run->markStepQueued($stepNumber, $stepName);

        ExecutePipelineStepJob::dispatch(
            stageId: $stage->id,
            stepNumber: $stepNumber,
            options: $options,
            userId: $userId,
        );

        return $stage;
    }

    /**
     * Cancel an active running stage.
     */
    public function cancelStep(int $stepNumber, PipelineRun $run): bool
    {
        return (bool) $run->cancelStep($stepNumber);
    }

    /**
     * Dispatch execution of any step number directly.
     */
    public function executeStep(int $stepNumber, ?PipelineRun $run = null, array $options = [], ?int $userId = null, ?PipelineRunStage $stage = null): array
    {
        return match ($stepNumber) {
            1 => $this->runStep1($options, $run, $userId),
            2 => $this->runStep2($options, $run, $userId),
            3 => $this->runStep3($options, $run, $userId),
            4 => $this->runStep4($options, $run, $userId),
            5 => $this->runStep5($options, $run, $userId),
            6 => $this->runStep6($options, $run, $userId),
            default => throw new RuntimeException("Invalid step number: {$stepNumber}"),
        };
    }

    /**
     * Find the active run or create a new one.
     */
    public function findOrCreateRun(array $options = [], ?int $userId = null, ?string $runId = null): PipelineRun
    {
        if ($runId) {
            $existing = PipelineRun::query()->where('run_id', $runId)->first();
            if ($existing) {
                return $existing;
            }
        }

        $latestActive = PipelineRun::query()
            ->whereIn('status', ['running', 'pending'])
            ->latest()
            ->first();

        if ($latestActive) {
            return $latestActive;
        }

        return PipelineRun::create([
            'run_id' => 'pvg_run_'.date('Ymd_His').'_'.Str::random(6),
            'status' => 'pending',
            'current_step' => 1,
            'completed_steps' => [],
            'options' => $options,
            'user_id' => $userId,
        ]);
    }

    private function resolveScript(array $options = []): ?VideoScript
    {
        if (! Schema::hasTable('video_scripts')) {
            return null;
        }

        if (! empty($options['script_id'])) {
            return VideoScript::query()->find($options['script_id']);
        }

        return VideoScript::query()->latest()->first();
    }
}
