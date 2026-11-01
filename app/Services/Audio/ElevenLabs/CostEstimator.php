<?php

namespace App\Services\Audio\ElevenLabs;

class CostEstimator
{
    /** Rate in micros ($0.000001) per 1 character: 300 micros = $0.0003 = $0.30 / 1k chars */
    public const DEFAULT_MICROS_PER_CHAR = 300;

    /**
     * Calculate estimated cost in micro-dollars for the given text.
     */
    public function estimateMicros(string $text, ?string $modelId = null): int
    {
        $charCount = mb_strlen(trim($text), 'UTF-8');
        $rate = $this->ratePerChar($modelId);

        return (int) ceil($charCount * $rate);
    }

    /**
     * Calculate actual cost in micro-dollars from character count.
     */
    public function actualMicros(int $charCount, ?string $modelId = null): int
    {
        $rate = $this->ratePerChar($modelId);

        return (int) ceil(max(0, $charCount) * $rate);
    }

    /**
     * Get estimated cost formatted in USD (e.g. 0.045).
     */
    public function estimateDollars(string $text, ?string $modelId = null): float
    {
        return $this->estimateMicros($text, $modelId) / 1_000_000.0;
    }

    /**
     * Sound effects: flat rate + char tier (approx $0.01 base + 300 micros/char).
     */
    public function estimateSoundEffectMicros(string $prompt, ?float $durationSeconds = null): int
    {
        $charCount = mb_strlen(trim($prompt), 'UTF-8');
        $base = 10_000; // $0.01 base per SFX generation
        $durationMultiplier = ($durationSeconds !== null && $durationSeconds > 5.0) ? (int) ceil($durationSeconds / 5.0) : 1;

        return ($base + ($charCount * 300)) * $durationMultiplier;
    }

    /**
     * Music: estimated cost based on duration.
     */
    public function estimateMusicMicros(float $durationSeconds): int
    {
        // ~$0.05 per 15s bed = 50,000 micros per 15s block
        $blocks = (int) ceil(max(1.0, $durationSeconds) / 15.0);

        return $blocks * 50_000;
    }

    protected function ratePerChar(?string $modelId = null): int
    {
        $configured = config('automation.audio.elevenlabs.cost_per_char_micros');
        if ($configured !== null && (int) $configured > 0) {
            return (int) $configured;
        }

        // Turbo / Flash models are roughly half cost
        if ($modelId && (str_contains($modelId, 'flash') || str_contains($modelId, 'turbo'))) {
            return 150;
        }

        return self::DEFAULT_MICROS_PER_CHAR;
    }
}

