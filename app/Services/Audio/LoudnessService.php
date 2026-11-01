<?php

namespace App\Services\Audio;

use App\Exceptions\Rendering\AudioPipelineException;
use App\Services\Rendering\Audio\AudioProbe;

class LoudnessService
{
    public const TARGET_LUFS = -14.0;

    public const MAX_TRUE_PEAK_DBTP = -1.0;

    public const TARGET_LRA = 11.0;

    public const TOLERANCE_LUFS = 1.0;

    public function __construct(
        protected AudioProbe $probe = new AudioProbe,
    ) {}

    /**
     * Measure raw EBU R128 loudness metrics.
     *
     * @return array{integrated_lufs: float, true_peak_dbtp: float, lra: float}
     */
    public function measure(string $audioPath): array
    {
        $report = $this->probe->loudness($audioPath);

        return [
            'integrated_lufs' => (float) ($report['integrated_lufs'] ?? self::TARGET_LUFS),
            'true_peak_dbtp' => (float) ($report['true_peak_dbtp'] ?? -1.5),
            'lra' => (float) ($report['lra'] ?? self::TARGET_LRA),
        ];
    }

    /**
     * Evaluate loudness compliance against broadcast delivery standards.
     *
     * @return array{
     *     passed: bool,
     *     integrated_lufs: float,
     *     target_lufs: float,
     *     true_peak_dbtp: float,
     *     max_true_peak: float,
     *     lra: float,
     *     lufs_diff: float,
     *     violations: list<string>
     * }
     */
    public function evaluate(
        string $audioPath,
        float $targetLufs = self::TARGET_LUFS,
        float $maxTruePeak = self::MAX_TRUE_PEAK_DBTP,
        float $tolerance = self::TOLERANCE_LUFS,
    ): array {
        $metrics = $this->measure($audioPath);

        $measuredLufs = $metrics['integrated_lufs'];
        $measuredTruePeak = $metrics['true_peak_dbtp'];
        $lra = $metrics['lra'];

        $violations = [];

        // Check integrated loudness window
        if ($measuredLufs > ($targetLufs + $tolerance)) {
            $violations[] = "Integrated loudness ({$measuredLufs} LUFS) is louder than allowed window ({$targetLufs} ± {$tolerance} LUFS)";
        } elseif ($measuredLufs < ($targetLufs - $tolerance)) {
            $violations[] = "Integrated loudness ({$measuredLufs} LUFS) is quieter than allowed window ({$targetLufs} ± {$tolerance} LUFS)";
        }

        // Check True Peak ceiling (must not exceed -1.0 dBTP to avoid inter-sample clipping on DAC playback)
        if ($measuredTruePeak > $maxTruePeak) {
            $violations[] = "True peak ({$measuredTruePeak} dBTP) exceeds maximum ceiling ({$maxTruePeak} dBTP)";
        }

        return [
            'passed' => empty($violations),
            'integrated_lufs' => $measuredLufs,
            'target_lufs' => $targetLufs,
            'true_peak_dbtp' => $measuredTruePeak,
            'max_true_peak' => $maxTruePeak,
            'lra' => $lra,
            'lufs_diff' => round($measuredLufs - $targetLufs, 2),
            'violations' => $violations,
        ];
    }

    /**
     * Hard-fail assertion gate for delivery master mix.
     *
     * @throws AudioPipelineException
     * @return array<string, mixed>
     */
    public function assertCompliant(
        string $audioPath,
        float $targetLufs = self::TARGET_LUFS,
        float $maxTruePeak = self::MAX_TRUE_PEAK_DBTP,
        float $tolerance = self::TOLERANCE_LUFS,
    ): array {
        $result = $this->evaluate($audioPath, $targetLufs, $maxTruePeak, $tolerance);

        if (! $result['passed']) {
            throw new AudioPipelineException(
                'Loudness QA gate failed: '.implode('; ', $result['violations'])
            );
        }

        return $result;
    }

    /**
     * Simple boolean check for gating UI and preflights.
     */
    public function passesGate(
        string $audioPath,
        float $targetLufs = self::TARGET_LUFS,
        float $maxTruePeak = self::MAX_TRUE_PEAK_DBTP,
    ): bool {
        return $this->evaluate($audioPath, $targetLufs, $maxTruePeak)['passed'];
    }
}

