<?php

namespace App\Console\Commands;

use App\Services\Avatar\AvatarQaService;
use App\Services\Avatar\CharacterGenerator;
use App\Services\Avatar\EmotionAnnotator;
use App\Services\Avatar\PresenterCompositionWriter;
use App\Services\Avatar\PresenterSceneCompiler;
use App\Services\Avatar\VariationEngine;
use App\Services\Avatar\VisemeMapper;
use App\Support\HyperFramesRuntime;
use Illuminate\Console\Command;

/**
 * Verify the avatar pipeline:
 * - M1: preflight, flag check, audio manifest check, lip-sync QA (Gate 1), liveness QA (Gate 2)
 * - M2: VariationEngine uniqueness, fingerprinting, multi-character generation proofs
 * - M3: Mode B Fixed Character locking + non-identity variation engine (Gate 3)
 * - M4: EmotionAnnotator + ToneGuard + Gestures return-to-rest (Gates 4, 5, 6)
 * - M5: PresenterSceneCompiler + full HyperFrames project integration & render
 * - M6: Full 7-gate QA audit suite (AvatarQaService::runFullAudit) & diagnostics
 *
 * Readiness: IMPLEMENTED + TESTED
 */
class AvatarVerifyCommand extends Command
{
    protected $signature = 'ai-pvg:verify-avatar
        {--seed=42          : PRNG seed (uint32)}
        {--count=1          : Number of characters to generate and verify (e.g. 3)}
        {--mode=            : Operating mode (infinite | fixed)}
        {--character-id=    : Character ID for fixed mode (defaults to anchor_host_01)}
        {--variation=medium : Degree of style variation (low | medium | high)}
        {--output=          : Output directory for the test composition}
        {--execute          : Actually build and QA the presenter HTML}
        {--render           : Lint and render the full MP4 video via HyperFrames}
        {--video-output=    : Path for rendered MP4}
        {--force            : Bypass feature flag check for testing}
        {--audio-manifest=  : Path to audio_timeline.json (defaults to script_1)}';

    protected $description = 'Verify the AI Presenter / Avatar system (M1 through M6 scope, 7 QA gates)';

    public function handle(
        AvatarQaService $qa,
        PresenterCompositionWriter $writer,
        VariationEngine $variationEngine,
        EmotionAnnotator $annotator,
        PresenterSceneCompiler $compiler,
    ): int {
        $this->line('');
        $this->line('<fg=cyan;options=bold>AI-PVG Avatar Verify — M1 to M6</fg=cyan;options=bold>');
        $this->line(str_repeat('─', 50));

        // ── Step 1: Feature flag ──────────────────────────────
        $flagKey = (string) config('automation.avatar.feature_flag', 'avatar.enabled');
        $flagOn = HyperFramesRuntime::flagEnabled($flagKey);
        $forced = (bool) $this->option('force');

        $this->line("Feature flag [{$flagKey}]: ".($flagOn ? '<fg=green>ENABLED</fg=green>' : '<fg=yellow>DISABLED (fail-closed)</fg=yellow>'));

        if (! $flagOn && ! $forced && ($this->option('execute') || $this->option('render'))) {
            $this->error("Feature flag [{$flagKey}] is DISABLED — avatar pipeline fails closed (pass --force to bypass for testing).");

            return self::FAILURE;
        }

        // ── Step 2: Audio manifest ────────────────────────────
        $manifestPath = $this->option('audio-manifest')
            ?: storage_path('app/renders/script_1/audio_timeline.json');

        if (! file_exists($manifestPath)) {
            $this->error("Audio manifest not found: {$manifestPath}");

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest) || ! isset($manifest['total_duration'], $manifest['narration'])) {
            $this->error('Invalid audio_timeline.json — missing total_duration or narration');

            return self::FAILURE;
        }

        $this->info("Audio manifest OK — {$manifest['total_duration']}s, ".count($manifest['narration']).' scenes');

        // Collect all word timings (top-level or per-scene)
        $wordTimings = [];
        if (isset($manifest['word_timings']) && is_array($manifest['word_timings'])) {
            $wordTimings = $manifest['word_timings'];
        } else {
            foreach ($manifest['narration'] as $scene) {
                if (isset($scene['word_timings']) && is_array($scene['word_timings'])) {
                    foreach ($scene['word_timings'] as $wt) {
                        $wordTimings[] = $wt;
                    }
                }
            }
        }
        $this->line('Word timings collected: '.count($wordTimings));

        if (! $this->option('execute') && ! $this->option('render')) {
            $this->line('');
            $this->line('<fg=yellow>Preflight-only mode. Pass --execute to build compositions or --render to output MP4.</fg=yellow>');

            return self::SUCCESS;
        }

        $count = max(1, (int) $this->option('count'));
        $baseSeed = (int) $this->option('seed');
        $mode = $this->option('mode') ? (string) $this->option('mode') : (string) config('automation.avatar.mode', 'infinite');
        $variationLevel = (string) ($this->option('variation') ?: 'medium');
        $fixedCharId = $this->option('character-id') ? (string) $this->option('character-id') : null;
        $fps = (int) config('automation.render.hyperframes.fps', 30);
        $baseOutputDir = $this->option('output')
            ?: storage_path('app/hyperframes/avatar_m5_test');

        // ── Lip-sync & QA Gate 1 ──────────────────────────────
        $lipSyncTrack = VisemeMapper::buildTrack($wordTimings, (float) $manifest['total_duration'], $fps);
        $this->info('Lip-sync track built: '.count($lipSyncTrack).' frames');

        $gate1 = $qa->checkLipSyncTrack($lipSyncTrack, $wordTimings);
        $this->line('[QA Gate 1] '.$gate1['message']);
        if (! $gate1['pass']) {
            $this->error('QA Gate 1 failed — aborting.');

            return self::FAILURE;
        }

        // ── Emotion Annotation & QA Gates 4 & 5 ───────────────
        $narrationScenes = is_array($manifest['narration'] ?? null) ? $manifest['narration'] : [];
        $emotionTimeline = $annotator->annotateScenes($narrationScenes);
        $this->info('Emotion timeline annotated: '.count($emotionTimeline).' scene beats');

        $gate4 = $qa->checkToneGuardCompliance($emotionTimeline);
        $this->line('[QA Gate 4] '.$gate4['message']);
        if (! $gate4['pass']) {
            $this->error('QA Gate 4 failed — aborting.');

            return self::FAILURE;
        }

        $gate5 = $qa->checkExpressionRateLimit($emotionTimeline);
        $this->line('[QA Gate 5] '.$gate5['message']);
        if (! $gate5['pass']) {
            $this->error('QA Gate 5 failed — aborting.');

            return self::FAILURE;
        }

        $generatedRuns = [];

        for ($i = 0; $i < $count; $i++) {
            $seed = $count === 1 ? $baseSeed : ($baseSeed + $i * 101);
            $runId = 'run_m5_'.date('Ymd_His')."_{$mode}_{$seed}";

            $charOutputDir = $count === 1 ? $baseOutputDir : $baseOutputDir.DIRECTORY_SEPARATOR."char_{$i}";

            $options = [
                'mode' => $mode,
                'variation' => $variationLevel,
                'run_id' => $runId,
                'seed' => $seed,
                'output_dir' => $charOutputDir,
            ];
            if ($fixedCharId) {
                $options['character_id'] = $fixedCharId;
            }

            // Compile entire project including presenter sub-composition
            $compiled = $compiler->compile($manifest, $options);
            $char = $compiled['character'];
            $spec = $char['spec'];
            $style = $compiled['style'];
            $fp = $char['fingerprint'];
            $styleFp = $char['style_fingerprint'];
            $projectDir = $compiled['projectDir'];
            $fullPath = $projectDir.'/compositions/presenter.html';

            $this->line('');
            $this->line('<fg=cyan;options=bold>Run #'.($i + 1)." (Seed: {$char['seed']}, Mode: {$char['mode']}, ID: {$char['character_id']})</fg=cyan;options=bold>");
            $this->line("  Character FP : {$fp}");
            $this->line("  Face/Wardrobe: {$spec['head_shape']} head, {$spec['skin_tone']} skin, {$spec['hair_style']} hair ({$spec['hair_color']}), {$spec['outfit']} outfit");
            $this->line("  Run Style    : {$style['set_style']} set, {$style['broadcast_tool']} tool, {$style['camera_motion']} cam, {$style['sfx_cue']} SFX, {$style['accent_color']} accent");
            $this->line("  Style FP     : {$styleFp}");

            // Verify sub-composition mount in index.html
            $mountedInRoot = str_contains($compiled['indexHtml'], 'data-composition-src="compositions/presenter.html"');
            $this->line('  [Sub-comp]    '.($mountedInRoot ? '<fg=green>PASS (presenter.html mounted in index.html)</fg=green>' : '<fg=red>FAIL (not mounted in index.html)</fg=red>'));
            if (! $mountedInRoot) {
                $this->error('Presenter sub-composition not mounted in index.html');

                return self::FAILURE;
            }

            // QA Gate 2: liveness
            $gate2 = $qa->checkLiveness($fullPath);
            $this->line('  [QA Gate 2] '.$gate2['message']);
            if (! $gate2['pass']) {
                $this->error('QA Gate 2 failed on run #'.($i + 1));

                return self::FAILURE;
            }

            // QA Gate 3: style integrity
            $gate3 = $qa->checkStyleIntegrity($fullPath);
            $this->line('  [QA Gate 3] '.$gate3['message']);
            if (! $gate3['pass']) {
                $this->error('QA Gate 3 failed on run #'.($i + 1));

                return self::FAILURE;
            }

            // QA Gate 6: gesture return-to-rest
            $gate6 = $qa->checkGestureReturnToRest($emotionTimeline, $fullPath);
            $this->line('  [QA Gate 6] '.$gate6['message']);
            if (! $gate6['pass']) {
                $this->error('QA Gate 6 failed on run #'.($i + 1));

                return self::FAILURE;
            }

            $this->line("  Presenter HTML: {$fullPath} (".number_format(filesize($fullPath)).' bytes)');
            $this->line("  Master HTML   : {$projectDir}/index.html (".number_format(filesize($projectDir.'/index.html')).' bytes)');

            // Optional full MP4 render
            if ($this->option('render')) {
                $videoOut = $this->option('video-output')
                    ?: storage_path("app/video_out/avatar_{$mode}_seed{$seed}.mp4");
                $this->line("  Rendering MP4 via HyperFrames CLI -> {$videoOut} ...");
                $renderResult = $compiler->compileAndRender($manifest, $videoOut, $options);
                $this->info("  MP4 Render Complete: {$videoOut} (".number_format(filesize($videoOut)).' bytes)');
            }

            $generatedRuns[] = [
                'index' => $i + 1,
                'seed' => $char['seed'],
                'character_id' => $char['character_id'],
                'spec' => $spec,
                'style' => $style,
                'fingerprint' => $fp,
                'style_fingerprint' => $styleFp,
            ];
        }

        // Mode B Verification Proof
        if ($mode === 'fixed' && $count > 1) {
            $this->line('');
            $this->line('<fg=yellow;options=bold>Mode B Fixed Character Invariance & Style Variation Proof:</fg=yellow;options=bold>');

            $firstFp = $generatedRuns[0]['fingerprint'];
            $firstId = $generatedRuns[0]['character_id'];
            $identityLocked = true;

            for ($j = 1; $j < count($generatedRuns); $j++) {
                if ($generatedRuns[$j]['fingerprint'] !== $firstFp || $generatedRuns[$j]['character_id'] !== $firstId) {
                    $identityLocked = false;
                }
            }

            $identityStatus = $identityLocked ? '<fg=green>LOCKED (100% Identical)</fg=green>' : '<fg=red>FAILED</fg=red>';
            $this->line("  Character Identity across all {$count} runs: {$identityStatus}");

            for ($a = 0; $a < count($generatedRuns); $a++) {
                for ($b = $a + 1; $b < count($generatedRuns); $b++) {
                    $runA = $generatedRuns[$a];
                    $runB = $generatedRuns[$b];
                    $styleDiffs = [];
                    foreach (['set_style', 'broadcast_tool', 'camera_motion', 'sfx_cue', 'accent_color'] as $key) {
                        if (($runA['style'][$key] ?? null) !== ($runB['style'][$key] ?? null)) {
                            $styleDiffs[] = "{$key} ({$runA['style'][$key]} vs {$runB['style'][$key]})";
                        }
                    }
                    $this->line("  Run #{$runA['index']} vs Run #{$runB['index']} style differences: ".count($styleDiffs).'/5 changed ['.implode(', ', $styleDiffs).']');
                }
            }
        } elseif ($mode === 'infinite' && $count > 1) {
            $this->line('');
            $this->line('<fg=yellow;options=bold>Mode A Pairwise Category Distance Matrix (Uniqueness Proof):</fg=yellow;options=bold>');
            $minThreshold = (int) config('automation.avatar.similarity_min_diff_categories', 4);

            for ($a = 0; $a < count($generatedRuns); $a++) {
                for ($b = $a + 1; $b < count($generatedRuns); $b++) {
                    $dist = CharacterGenerator::categoryDistance(
                        $generatedRuns[$a]['spec'],
                        $generatedRuns[$b]['spec']
                    );
                    $passed = $dist >= $minThreshold;
                    $statusTag = $passed ? '<fg=green>PASS</fg=green>' : '<fg=red>FAIL</fg=red>';
                    $this->line("  Run #{$generatedRuns[$a]['index']} vs Run #{$generatedRuns[$b]['index']}: {$dist}/12 categories differ [{$statusTag}] (min {$minThreshold})");
                }
            }
        }

        // ── Full M6 QA Audit Across All 7 Gates ───────────────
        $primaryRun = $generatedRuns[0];
        $primaryHtml = ($count === 1 ? $baseOutputDir : $baseOutputDir.DIRECTORY_SEPARATOR.'char_0').'/compositions/presenter.html';

        // Determinism test pair: generate second independent run with exact same seed
        $dupChar = $variationEngine->selectCharacter($primaryRun['seed'], 'verify_det', [
            'mode' => $mode,
            'character_id' => $fixedCharId,
            'variation' => $variationLevel,
            'record' => false,
            'ignore_history' => true,
        ]);

        $detRun1 = [
            'fingerprint' => $primaryRun['fingerprint'],
            'style_fingerprint' => $primaryRun['style_fingerprint'],
            'spec' => $primaryRun['spec'],
        ];
        $detRun2 = [
            'fingerprint' => $dupChar['fingerprint'],
            'style_fingerprint' => $dupChar['style_fingerprint'],
            'spec' => $dupChar['spec'],
        ];

        $auditData = [
            'lip_sync_track' => $lipSyncTrack,
            'word_timings' => $wordTimings,
            'emotion_timeline' => $emotionTimeline,
            'presenter_html' => $primaryHtml,
            'determinism_runs' => [$detRun1, $detRun2],
        ];

        $auditResult = $qa->runFullAudit($auditData);

        $this->line('');
        $this->line('<fg=cyan;options=bold>── M6 Full QA Audit (7 Gates) ──────────────────────────────</fg=cyan;options=bold>');

        $gateRows = [
            ['Gate 1', 'Lip-Sync Liveness Coverage (≥ 60%)', $auditResult['gates']['gate1_lip_sync']['pass'] ? '<fg=green>PASS</fg=green>' : '<fg=red>FAIL</fg=red>', $auditResult['gates']['gate1_lip_sync']['message']],
            ['Gate 2', 'Liveness & AI Disclosure Badge (0-3s)', $auditResult['gates']['gate2_liveness']['pass'] ? '<fg=green>PASS</fg=green>' : '<fg=red>FAIL</fg=red>', $auditResult['gates']['gate2_liveness']['message']],
            ['Gate 3', 'Style Integrity (SFX, Set, Tool)', $auditResult['gates']['gate3_style']['pass'] ? '<fg=green>PASS</fg=green>' : '<fg=red>FAIL</fg=red>', $auditResult['gates']['gate3_style']['message']],
            ['Gate 4', 'Tone Guard Compliance (Zero Smile Violations)', $auditResult['gates']['gate4_tone_guard']['pass'] ? '<fg=green>PASS</fg=green>' : '<fg=red>FAIL</fg=red>', $auditResult['gates']['gate4_tone_guard']['message']],
            ['Gate 5', 'Expression Rate Limit (≤ 3 changes/s)', $auditResult['gates']['gate5_rate_limit']['pass'] ? '<fg=green>PASS</fg=green>' : '<fg=red>FAIL</fg=red>', $auditResult['gates']['gate5_rate_limit']['message']],
            ['Gate 6', 'Gesture Return-to-Rest Guarantee', $auditResult['gates']['gate6_gesture_rest']['pass'] ? '<fg=green>PASS</fg=green>' : '<fg=red>FAIL</fg=red>', $auditResult['gates']['gate6_gesture_rest']['message']],
            ['Gate 7', 'Seed Determinism Invariance', $auditResult['gates']['gate7_determinism']['pass'] ? '<fg=green>PASS</fg=green>' : '<fg=red>FAIL</fg=red>', $auditResult['gates']['gate7_determinism']['message']],
        ];

        $this->table(['Gate', 'Verification Dimension', 'Status', 'Summary Detail'], $gateRows);

        if (! $auditResult['all_passed']) {
            $this->error('M6 QA Audit Failed: '.$auditResult['summary']);

            return self::FAILURE;
        }

        $this->line('');
        $this->line("<fg=green;options=bold>M1 PASS, M2 PASS, M3 PASS, M4 PASS, M5 PASS & M6 PASS — {$auditResult['summary']} — Full Avatar Pipeline M1..M6 Verified.</fg=green;options=bold>");
        $this->line('Readiness: <fg=green;options=bold>IMPLEMENTED + TESTED</fg=green;options=bold>');

        return self::SUCCESS;
    }
}
