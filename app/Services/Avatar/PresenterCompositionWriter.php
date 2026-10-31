<?php

namespace App\Services\Avatar;

use Illuminate\Support\Facades\View;

/**
 * Renders the presenter.blade.php Blade view into a standalone HTML file
 * inside the HyperFrames project directory so it can be used as a
 * sub-composition via data-composition-src="compositions/presenter.html".
 */
final class PresenterCompositionWriter
{
    /**
     * Build and write the presenter sub-composition HTML.
     *
     * @param  array  $charSpec  Validated CharacterSpec array
     * @param  array  $lipSyncTrack  Output of VisemeMapper::buildTrack()
     * @param  array  $audioTimeline  Decoded audio_timeline.json
     * @param  string  $projectDir  Absolute path to the HyperFrames project
     * @param  int  $seed  PRNG seed (stored on the run)
     * @return string Relative path from project root: "compositions/presenter.html"
     */
    public function write(
        array $charSpec,
        array $lipSyncTrack,
        array $audioTimeline,
        string $projectDir,
        int $seed,
        int $fps = 30,
        array $styleSpec = [],
        array $emotionTimeline = [],
    ): string {
        $audioDuration = (float) ($audioTimeline['total_duration'] ?? 10.0);

        if ($styleSpec === []) {
            $styleSpec = PresenterStyleGenerator::generate(new AvatarPrng($seed));
        }

        if ($emotionTimeline === []) {
            $narrationScenes = is_array($audioTimeline['narration'] ?? null) ? $audioTimeline['narration'] : [];
            $emotionTimeline = (new EmotionAnnotator)->annotateScenes($narrationScenes);
        }

        // Derive palette colours from charSpec
        $skinTone = $charSpec['skin_tone'];
        $skinLight = self::lighten($skinTone, 0.15);
        $hairColor = $charSpec['hair_color'];
        $irisColor = '#4A6FA5'; // stylised iris; not derived from real person
        $lipColor = self::lighten($skinTone, -0.05);
        $lipLight = self::lighten($skinTone, 0.08);
        $lipDark = self::darken($skinTone, 0.20);
        $shadowTone = self::darken($skinTone, 0.18);

        [$bgDark, $bgLight, $outfitColor, $outfitDark] = self::paletteToColors($charSpec['palette']);

        $html = View::make('hyperframes.presenter', [
            'seed' => $seed,
            'fps' => $fps,
            'audioDuration' => $audioDuration,
            'lipSyncTrack' => $lipSyncTrack,
            'charSpec' => $charSpec,
            'styleSpec' => $styleSpec,
            'setStyle' => $styleSpec['set_style'] ?? 'gradient_minimal',
            'broadcastTool' => $styleSpec['broadcast_tool'] ?? 'lower_third_pill',
            'cameraMotion' => $styleSpec['camera_motion'] ?? 'calm_editorial',
            'sfxCue' => $styleSpec['sfx_cue'] ?? 'whoosh',
            'transitionStyle' => $styleSpec['transition_style'] ?? 'slide_up',
            'accentColor' => $styleSpec['accent_color'] ?? '#3B82F6',
            'toolHeadline' => $styleSpec['tool_headline'] ?? 'BREAKING DISPATCH',
            'toolSub' => $styleSpec['tool_sub'] ?? 'VERIFIED REPORT',
            'emotionTimeline' => $emotionTimeline,
            // Shape
            'headShape' => $charSpec['head_shape'],
            'hairStyle' => $charSpec['hair_style'],
            'browStyle' => $charSpec['brow_style'],
            'glasses' => $charSpec['glasses'],
            // Colours
            'skinTone' => $skinTone,
            'skinLight' => $skinLight,
            'hairColor' => $hairColor,
            'irisColor' => $irisColor,
            'lipColor' => $lipColor,
            'lipLight' => $lipLight,
            'lipDark' => $lipDark,
            'shadowTone' => $shadowTone,
            'width' => 1080,
            'height' => 1920,
            'bgDark' => $bgDark,
            'bgLight' => $bgLight,
            'outfitColor' => $outfitColor,
            'outfitDark' => $outfitDark,
        ])->render();

        $compositionsDir = $projectDir.DIRECTORY_SEPARATOR.'compositions';
        if (! is_dir($compositionsDir)) {
            mkdir($compositionsDir, 0755, true);
        }

        $outputPath = $compositionsDir.DIRECTORY_SEPARATOR.'presenter.html';
        file_put_contents($outputPath, $html);

        return 'compositions/presenter.html';
    }

    /** Lighten or darken a hex colour by a fraction (positive = lighter). */
    private static function lighten(string $hex, float $fraction): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        if ($fraction >= 0) {
            $r = (int) min(255, $r + (255 - $r) * $fraction);
            $g = (int) min(255, $g + (255 - $g) * $fraction);
            $b = (int) min(255, $b + (255 - $b) * $fraction);
        } else {
            return self::darken($hex, -$fraction);
        }

        return self::rgbToHex($r, $g, $b);
    }

    private static function darken(string $hex, float $fraction): string
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $r = (int) max(0, $r * (1 - $fraction));
        $g = (int) max(0, $g * (1 - $fraction));
        $b = (int) max(0, $b * (1 - $fraction));

        return self::rgbToHex($r, $g, $b);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    private static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /** @return array{0:string,1:string,2:string,3:string} [bgDark, bgLight, outfitColor, outfitDark] */
    private static function paletteToColors(string $palette): array
    {
        return match ($palette) {
            'dark_pro' => ['#0A0F1A', '#141E2E', '#1E2A3A', '#111820'],
            'light_editorial' => ['#E8EAF0', '#F5F6FA', '#3A4A6B', '#2A3A5B'],
            'neon_tech' => ['#050B15', '#0A1525', '#003366', '#001F44'],
            'warm_studio' => ['#1A100A', '#2A1A10', '#5A2D10', '#3A1A08'],
            'cool_blue' => ['#080E18', '#101828', '#1A3050', '#0E1E38'],
            default => ['#0A0F1A', '#141E2E', '#1E2A3A', '#111820'],
        };
    }
}
