<?php

namespace App\Services\Avatar;

/**
 * PresenterStyleGenerator creates non-identity run variations:
 * broadcast tools, set background styling, camera motions, transitions, and SFX cues.
 *
 * In Mode B (Fixed Character), the character's facial features and wardrobe stay locked,
 * while PresenterStyleGenerator provides dynamic variety across runs.
 */
final class PresenterStyleGenerator
{
    public const SET_STYLES = [
        'cyber_grid',
        'modern_loft',
        'newsroom_blur',
        'gradient_minimal',
        'dark_emitter',
    ];

    public const BROADCAST_TOOLS = [
        'lower_third_pill',
        'breaking_ticker',
        'corner_topic_card',
        'side_stat_pill',
    ];

    public const CAMERA_MOTIONS = [
        'calm_editorial',
        'slow_push_in',
        'dynamic_float',
        'static_macro',
    ];

    public const SFX_CUES = [
        'whoosh',
        'impact',
        'sub_drop',
        'snap',
    ];

    public const TRANSITION_STYLES = [
        'slide_up',
        'wipe',
        'fade',
    ];

    public const ACCENT_COLORS = [
        '#3B82F6', // Cobalt
        '#10B981', // Emerald
        '#EC4899', // Hot Pink
        '#8B5CF6', // Electric Violet
        '#F59E0B', // Amber
        '#06B6D4', // Cyan
    ];

    /**
     * Generate a deterministic non-identity style spec for a run.
     *
     * @return array<string, mixed>
     */
    public static function generate(AvatarPrng $prng, string $variationLevel = 'medium'): array
    {
        $setStyle = match ($variationLevel) {
            'low' => $prng->pick(['gradient_minimal', 'modern_loft']),
            'high' => $prng->pick(['cyber_grid', 'dark_emitter', 'newsroom_blur']),
            default => $prng->pick(self::SET_STYLES),
        };

        $broadcastTool = match ($variationLevel) {
            'low' => $prng->pick(['lower_third_pill', 'side_stat_pill']),
            default => $prng->pick(self::BROADCAST_TOOLS),
        };

        $cameraMotion = match ($variationLevel) {
            'low' => 'calm_editorial',
            'high' => $prng->pick(['slow_push_in', 'dynamic_float']),
            default => $prng->pick(self::CAMERA_MOTIONS),
        };

        $sfxCue = $prng->pick(self::SFX_CUES);
        $transitionStyle = $prng->pick(self::TRANSITION_STYLES);
        $accentColor = $prng->pick(self::ACCENT_COLORS);

        $headlines = [
            'BREAKING DISPATCH',
            'AI INTELLIGENCE',
            'GLOBAL HEADLINES',
            'SYSTEM UPDATE',
            'SPECIAL COVERAGE',
        ];

        $sublines = [
            'VERIFIED REPORT',
            'LIVE BRIEFING',
            'FACT-CHECKED',
            'MARKET SIGNAL',
            'RECORD EVENT',
        ];

        return [
            'set_style' => $setStyle,
            'broadcast_tool' => $broadcastTool,
            'camera_motion' => $cameraMotion,
            'sfx_cue' => $sfxCue,
            'transition_style' => $transitionStyle,
            'accent_color' => $accentColor,
            'variation_level' => $variationLevel,
            'tool_headline' => $prng->pick($headlines),
            'tool_sub' => $prng->pick($sublines),
        ];
    }

    /**
     * Compute a SHA-256 fingerprint representing only the non-identity style dimensions.
     *
     * @param  array<string, mixed>  $styleSpec
     */
    public static function styleFingerprint(array $styleSpec): string
    {
        $subset = [
            'set_style' => $styleSpec['set_style'] ?? '',
            'broadcast_tool' => $styleSpec['broadcast_tool'] ?? '',
            'camera_motion' => $styleSpec['camera_motion'] ?? '',
            'sfx_cue' => $styleSpec['sfx_cue'] ?? '',
            'transition_style' => $styleSpec['transition_style'] ?? '',
            'accent_color' => $styleSpec['accent_color'] ?? '',
        ];

        ksort($subset);

        return hash('sha256', (string) json_encode($subset, JSON_UNESCAPED_SLASHES));
    }
}
