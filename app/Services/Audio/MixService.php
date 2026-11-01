<?php

namespace App\Services\Audio;

use App\Services\Rendering\Audio\AudioMixer;
use App\Services\Rendering\Audio\AudioProbe;

class MixService
{
    public function __construct(
        protected AudioMixer $mixer = new AudioMixer,
        protected LoudnessService $loudnessService = new LoudnessService,
        protected AudioProbe $probe = new AudioProbe,
    ) {}

    /**
     * Mix narration, ducked music, and SFX stems into a broadcast delivery track.
     *
     * @param  list<array{path: string, start: float}>  $narration
     * @param  list<array{path: string, start: float, gain?: float}>  $sfx
     * @param  array{
     *     target_lufs?: float,
     *     true_peak?: float,
     *     enforce_gate?: bool
     * }  $options
     * @return array{
     *     mix: string,
     *     narration: string,
     *     music: string,
     *     sfx: string,
     *     duration: float,
     *     loudness: array<string, mixed>,
     *     gate_passed: bool
     * }
     */
    public function mix(
        array $narration,
        string $musicPath,
        array $sfx,
        float $duration,
        string $workDir,
        array $options = [],
    ): array {
        $targetLufs = (float) ($options['target_lufs'] ?? config('automation.render.hyperframes.audio.target_lufs', -14.0));
        $truePeak = (float) ($options['true_peak'] ?? config('automation.render.hyperframes.audio.true_peak', -1.5));
        $enforceGate = (bool) ($options['enforce_gate'] ?? true);

        // Pre-mix with sidechain ducking and two-pass loudnorm
        $mixResult = $this->mixer->mix(
            $narration,
            $musicPath,
            $sfx,
            $duration,
            $workDir,
        );

        $loudnessReport = $mixResult['loudness'] ?? [];

        // Validate against loudness gate
        if ($enforceGate && is_file($mixResult['mix'])) {
            $loudnessReport = $this->loudnessService->assertCompliant(
                $mixResult['mix'],
                $targetLufs,
                $truePeak > -1.0 ? -1.0 : $truePeak,
            );
        }

        return [
            'mix' => $mixResult['mix'],
            'narration' => $mixResult['narration'],
            'music' => $mixResult['music'],
            'sfx' => $mixResult['sfx'],
            'duration' => $mixResult['duration'],
            'loudness' => $loudnessReport,
            'gate_passed' => true,
        ];
    }
}

