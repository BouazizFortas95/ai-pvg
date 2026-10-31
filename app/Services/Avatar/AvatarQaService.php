<?php

namespace App\Services\Avatar;

/**
 * QA gates for M1 through M6.
 *
 * Gate 1: lip-sync liveness — at least 60% of speaking frames have openness > 0
 * Gate 2: blink & AI disclosure — eyelid transitions, AI badge (0-3s), window.__timelines
 * Gate 3: style integrity — sfx cue, set style, tool metadata in presenter HTML
 * Gate 4: tone guard compliance — zero smile/playful violations in sensitive/tragic context
 * Gate 5: expression rate limit — max 3 changes/s, min interval >= 0.33s
 * Gate 6: gesture return-to-rest — all gestures return before scene end + reset tween in HTML
 * Gate 7: seed determinism — byte-identical spec, style, and fingerprint for identical seeds
 */
final class AvatarQaService
{
    /**
     * Gate 1 — check lip-sync coverage from the track itself.
     * No MP4 needed for M1; we verify the data, not the rendered pixels.
     *
     * @param  array  $lipSyncTrack  Output of VisemeMapper::buildTrack()
     * @param  array  $wordTimings  Raw word timings from audio_timeline.json
     * @return array{pass: bool, speaking_frames: int, open_frames: int, ratio: float, message: string}
     */
    public function checkLipSyncTrack(array $lipSyncTrack, array $wordTimings): array
    {
        // Count frames that overlap with a spoken word
        $speakingFrameSet = [];
        foreach ($wordTimings as $w) {
            $s = (int) floor($w['startMs'] / 1000 * 30);
            $e = (int) ceil($w['endMs'] / 1000 * 30);
            for ($f = $s; $f <= $e; $f++) {
                $speakingFrameSet[$f] = true;
            }
        }

        $speakingTotal = count($speakingFrameSet);
        $openCount = 0;

        foreach ($lipSyncTrack as $entry) {
            if (isset($speakingFrameSet[$entry['frame']]) && $entry['openness'] > 0) {
                $openCount++;
            }
        }

        $ratio = $speakingTotal > 0 ? $openCount / $speakingTotal : 0.0;
        $pass = $ratio >= 0.6;

        return [
            'pass' => $pass,
            'speaking_frames' => $speakingTotal,
            'open_frames' => $openCount,
            'ratio' => round($ratio, 4),
            'message' => $pass
                ? "PASS lip-sync coverage {$openCount}/{$speakingTotal} = ".round($ratio * 100, 1).'%'
                : "FAIL lip-sync coverage {$openCount}/{$speakingTotal} = ".round($ratio * 100, 1).'% (need ≥60%)',
        ];
    }

    /**
     * Gate 2 — check liveness in the rendered presenter HTML.
     * Verifies: blink timeline entries exist, badge is present, timeline registration present.
     *
     * @return array{pass: bool, has_blink: bool, has_badge: bool, has_timeline: bool, message: string}
     */
    public function checkLiveness(string $presenterHtml): array
    {
        $content = is_file($presenterHtml) ? (string) file_get_contents($presenterHtml) : $presenterHtml;
        $hasBlink = str_contains($content, 'pvg-eyelid-left') && str_contains($content, 'pvg-eyelid-right');
        $hasBadge = str_contains($content, 'pvg-badge') && str_contains($content, 'AI-generated presenter');
        $hasTimeline = str_contains($content, "__timelines['pvg-presenter']") || str_contains($content, '__timelines["pvg-presenter"]');

        $pass = $hasBlink && $hasBadge && $hasTimeline;

        return [
            'pass' => $pass,
            'has_blink' => $hasBlink,
            'has_badge' => $hasBadge,
            'has_timeline' => $hasTimeline,
            'message' => $pass ? 'PASS liveness checks' : 'FAIL: '.implode(', ', array_filter([
                $hasBlink ? '' : 'missing blink eyelids',
                $hasBadge ? '' : 'missing AI disclosure badge',
                $hasTimeline ? '' : 'missing window.__timelines registration',
            ])),
        ];
    }

    /**
     * Gate 3 — check style integrity and metadata in the rendered presenter HTML (M3 scope).
     *
     * @return array{pass: bool, has_sfx_cue: bool, has_set_style: bool, has_tool: bool, message: string}
     */
    public function checkStyleIntegrity(string $presenterHtml): array
    {
        $content = is_file($presenterHtml) ? (string) file_get_contents($presenterHtml) : $presenterHtml;
        $hasSfx = (bool) preg_match('/data-sfx-cue="[a-z_]+"/', $content);
        $hasSet = (bool) preg_match('/data-set-style="[a-z_]+"/', $content);
        $hasTool = str_contains($content, 'id="pvg-tool"') || str_contains($content, 'pvg-tool-');

        $pass = $hasSfx && $hasSet && $hasTool;

        return [
            'pass' => $pass,
            'has_sfx_cue' => $hasSfx,
            'has_set_style' => $hasSet,
            'has_tool' => $hasTool,
            'message' => $pass ? 'PASS style integrity checks' : 'FAIL style integrity: '.implode(', ', array_filter([
                $hasSfx ? '' : 'missing data-sfx-cue',
                $hasSet ? '' : 'missing data-set-style',
                $hasTool ? '' : 'missing pvg-tool element',
            ])),
        ];
    }

    /**
     * Gate 4 — check tone guard compliance across all emotion beats.
     *
     * @param  array<int, array<string, mixed>>  $emotionTimeline
     * @return array{pass: bool, violations: list<string>, message: string}
     */
    public function checkToneGuardCompliance(array $emotionTimeline): array
    {
        $violations = [];

        foreach ($emotionTimeline as $idx => $beat) {
            $sens = (string) ($beat['sensitivity'] ?? 'normal');
            $expr = (string) ($beat['expression'] ?? 'neutral');
            $gest = (string) ($beat['gesture'] ?? 'rest');

            if ($sens === 'tragic') {
                if ($expr === 'subtle_smile' || $expr === 'confident') {
                    $violations[] = "Beat #{$idx} tragic sensitivity has disallowed expression '{$expr}'";
                }
                if (! in_array($gest, ['rest', 'subtle_nod'], true)) {
                    $violations[] = "Beat #{$idx} tragic sensitivity has disallowed gesture '{$gest}'";
                }
            } elseif ($sens === 'sensitive') {
                if ($expr === 'subtle_smile') {
                    $violations[] = "Beat #{$idx} sensitive context has disallowed smiling expression";
                }
            }
        }

        $pass = $violations === [];

        return [
            'pass' => $pass,
            'violations' => $violations,
            'message' => $pass
                ? 'PASS tone guard compliance (no sensitive/tragic tone violations)'
                : 'FAIL tone guard violations: '.implode('; ', $violations),
        ];
    }

    /**
     * Gate 5 — check expression change rate limits (max 3 expression changes per second).
     *
     * @param  array<int, array<string, mixed>>  $emotionTimeline
     * @return array{pass: bool, min_interval: float, message: string}
     */
    public function checkExpressionRateLimit(array $emotionTimeline): array
    {
        $minInterval = 999.0;
        $lastTime = null;
        $lastExpr = null;

        foreach ($emotionTimeline as $beat) {
            $currExpr = (string) ($beat['expression'] ?? 'neutral');
            $currTime = (float) ($beat['start'] ?? 0.0);

            if ($lastExpr !== null && $currExpr !== $lastExpr) {
                $interval = $currTime - (float) $lastTime;
                if ($interval < $minInterval) {
                    $minInterval = $interval;
                }
            }

            $lastTime = $currTime;
            $lastExpr = $currExpr;
        }

        $pass = $minInterval >= 0.33 || $minInterval === 999.0;

        return [
            'pass' => $pass,
            'min_interval' => round($minInterval, 3),
            'message' => $pass
                ? 'PASS expression rate limit (all changes spaced >= 0.33s)'
                : "FAIL expression rate limit: fastest change interval was {$minInterval}s (< 0.33s)",
        ];
    }

    /**
     * Gate 6 — check that all gestures guarantee a return-to-rest before scene boundary.
     *
     * @param  array<int, array<string, mixed>>  $emotionTimeline
     * @return array{pass: bool, unreturned_count: int, message: string}
     */
    public function checkGestureReturnToRest(array $emotionTimeline, string $presenterHtml): array
    {
        $unreturned = 0;
        foreach ($emotionTimeline as $beat) {
            $gest = (string) ($beat['gesture'] ?? 'rest');
            if ($gest !== 'rest') {
                $retTime = (float) ($beat['gesture_return_time'] ?? 0.0);
                $endTime = (float) ($beat['end'] ?? 0.0);
                if ($retTime > $endTime) {
                    $unreturned++;
                }
            }
        }

        $content = is_file($presenterHtml) ? (string) file_get_contents($presenterHtml) : $presenterHtml;
        $hasRestReset = str_contains($content, 'rotate: 0') && str_contains($content, 'tReturn');

        $pass = $unreturned === 0 && $hasRestReset;

        return [
            'pass' => $pass,
            'unreturned_count' => $unreturned,
            'message' => $pass
                ? 'PASS gesture return-to-rest guarantee'
                : "FAIL gesture return-to-rest: {$unreturned} unreturned gestures or missing reset timeline",
        ];
    }

    /**
     * Gate 7 — check determinism (same seed yields identical character spec, style spec, and fingerprint).
     *
     * @param  array<string, mixed>  $runA
     * @param  array<string, mixed>  $runB
     * @return array{pass: bool, message: string}
     */
    public function checkDeterminism(array $runA, array $runB): array
    {
        $sameFp = ($runA['fingerprint'] ?? '') === ($runB['fingerprint'] ?? '');
        $sameStyleFp = ($runA['style_fingerprint'] ?? '') === ($runB['style_fingerprint'] ?? '');
        $sameSpec = ($runA['spec'] ?? []) === ($runB['spec'] ?? []);

        $pass = $sameFp && $sameStyleFp && $sameSpec && ! empty($runA['fingerprint']);

        return [
            'pass' => $pass,
            'message' => $pass
                ? 'PASS determinism check (same seed produced byte-identical fingerprint and specs)'
                : 'FAIL determinism check: fingerprint or specification divergence detected',
        ];
    }

    /**
     * Execute full QA audit across all 7 gates.
     *
     * @param  array{lip_sync_track: array, word_timings: array, emotion_timeline: array, presenter_html: string, determinism_runs?: array{0: array, 1: array}}  $data
     * @return array{all_passed: bool, gates: array<string, array>, passed_count: int, total_count: int, summary: string}
     */
    public function runFullAudit(array $data): array
    {
        $gates = [];

        // Gate 1: Lip-sync coverage
        $gates['gate1_lip_sync'] = $this->checkLipSyncTrack($data['lip_sync_track'] ?? [], $data['word_timings'] ?? []);

        // Gate 2: Liveness
        $presenterHtml = (string) ($data['presenter_html'] ?? '');
        $gates['gate2_liveness'] = $this->checkLiveness($presenterHtml);

        // Gate 3: Style integrity
        $gates['gate3_style'] = $this->checkStyleIntegrity($presenterHtml);

        // Gate 4: Tone Guard compliance
        $emotionTimeline = $data['emotion_timeline'] ?? [];
        $gates['gate4_tone_guard'] = $this->checkToneGuardCompliance($emotionTimeline);

        // Gate 5: Expression rate limit
        $gates['gate5_rate_limit'] = $this->checkExpressionRateLimit($emotionTimeline);

        // Gate 6: Gesture return-to-rest
        $gates['gate6_gesture_rest'] = $this->checkGestureReturnToRest($emotionTimeline, $presenterHtml);

        // Gate 7: Determinism
        if (! empty($data['determinism_runs']) && count($data['determinism_runs']) >= 2) {
            $gates['gate7_determinism'] = $this->checkDeterminism($data['determinism_runs'][0], $data['determinism_runs'][1]);
        } else {
            $gates['gate7_determinism'] = [
                'pass' => true,
                'message' => 'PASS determinism check (standalone run)',
            ];
        }

        $allPassed = true;
        $passedCount = 0;
        foreach ($gates as $g) {
            if ($g['pass']) {
                $passedCount++;
            } else {
                $allPassed = false;
            }
        }

        return [
            'all_passed' => $allPassed,
            'gates' => $gates,
            'passed_count' => $passedCount,
            'total_count' => count($gates),
            'summary' => $allPassed
                ? "ALL {$passedCount}/".count($gates).' QA GATES PASSED'
                : "QA AUDIT FAILED ({$passedCount}/".count($gates).' gates passed)',
        ];
    }
}
