<?php

namespace App\Services\Avatar;

use App\Models\AvatarCharacter;
use App\Models\VariationHistory;

/**
 * VariationEngine enforces character selection, run uniqueness, and non-identity styling.
 *
 * In Mode A (Infinite Variety):
 *   Generates a character from a seeded PRNG and ensures the visual categories
 *   differ by at least similarity_min_diff_categories (default 4) from all recent
 *   runs in the history window (default 200).
 *
 * In Mode B (Fixed Character):
 *   Locks the character identity from the avatar_characters table while allowing
 *   non-identity visual/motion variations (set, camera, tools, SFX).
 */
class VariationEngine
{
    public function __construct(
        protected CharacterGenerator $generator,
    ) {}

    /**
     * Select or generate a character according to operating mode and uniqueness rules.
     *
     * @param  array<string, mixed>  $options
     * @return array{character_id: string, spec: array<string, string>, fingerprint: string, style_spec: array<string, mixed>, style_fingerprint: string, seed: int, attempts: int, mode: string}
     */
    public function selectCharacter(int $seed, ?string $runId = null, array $options = []): array
    {
        $mode = (string) ($options['mode'] ?? config('automation.avatar.mode', 'infinite'));
        $historyWindow = (int) ($options['history_window'] ?? config('automation.avatar.history_window', 200));
        $minDiff = (int) ($options['min_diff'] ?? config('automation.avatar.similarity_min_diff_categories', 4));
        $variationLevel = (string) ($options['variation'] ?? config('automation.avatar.variation', 'medium'));
        $record = (bool) ($options['record'] ?? true);

        if ($mode === 'fixed') {
            return $this->resolveFixedCharacter($seed, $runId, $options, $variationLevel, $record);
        }

        // Mode A: Infinite Variety
        $history = collect();
        if (empty($options['ignore_history'])) {
            $historyQuery = VariationHistory::query()
                ->latest('id')
                ->limit($historyWindow);

            if ($runId !== null) {
                $historyQuery->where('run_id', '!=', $runId);
            }

            $history = $historyQuery->get();
        }

        $maxAttempts = (int) ($options['max_attempts'] ?? 30);
        $attempts = 0;
        $chosenSpec = null;
        $chosenFingerprint = null;
        $chosenSeed = $seed;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $attempts++;
            $currentSeed = $attempt === 0 ? $seed : (($seed + $attempt * 10007) & 0xFFFFFFFF);
            $candidateSpec = $this->generator->generateFromSeed($currentSeed);
            $candidateFingerprint = CharacterGenerator::fingerprint($candidateSpec);

            $isSufficientlyDifferent = true;
            foreach ($history as $pastRun) {
                $pastSpec = $pastRun->categories_json;
                if (is_array($pastSpec)) {
                    $distance = CharacterGenerator::categoryDistance($candidateSpec, $pastSpec);
                    if ($distance < $minDiff) {
                        $isSufficientlyDifferent = false;
                        break;
                    }
                }
            }

            if ($isSufficientlyDifferent) {
                $chosenSpec = $candidateSpec;
                $chosenFingerprint = $candidateFingerprint;
                $chosenSeed = $currentSeed;
                break;
            }
        }

        // Fallback if max attempts exceeded: take last generated candidate
        if ($chosenSpec === null) {
            $chosenSeed = $seed;
            $chosenSpec = $this->generator->generateFromSeed($chosenSeed);
            $chosenFingerprint = CharacterGenerator::fingerprint($chosenSpec);
        }

        $characterId = 'avatar_'.substr($chosenFingerprint, 0, 12);
        $styleSpec = PresenterStyleGenerator::generate(new AvatarPrng($chosenSeed), $variationLevel);
        $styleFingerprint = PresenterStyleGenerator::styleFingerprint($styleSpec);

        if ($record) {
            VariationHistory::create([
                'run_id' => $runId,
                'seed' => $chosenSeed,
                'variation_fingerprint' => $chosenFingerprint,
                'categories_json' => array_merge($chosenSpec, ['_style' => $styleSpec]),
            ]);
        }

        return [
            'character_id' => $characterId,
            'spec' => $chosenSpec,
            'fingerprint' => $chosenFingerprint,
            'style_spec' => $styleSpec,
            'style_fingerprint' => $styleFingerprint,
            'seed' => $chosenSeed,
            'attempts' => $attempts,
            'mode' => 'infinite',
        ];
    }

    /**
     * Mode B: resolve or create fixed character with non-identity dynamic run style.
     *
     * @param  array<string, mixed>  $options
     * @return array{character_id: string, spec: array<string, string>, fingerprint: string, style_spec: array<string, mixed>, style_fingerprint: string, seed: int, attempts: int, mode: string}
     */
    protected function resolveFixedCharacter(
        int $seed,
        ?string $runId,
        array $options,
        string $variationLevel,
        bool $record = true,
    ): array {
        $characterId = (string) ($options['character_id'] ?? config('automation.avatar.character_id') ?? 'anchor_host_01');

        $recordModel = AvatarCharacter::query()->where('character_id', $characterId)->first();

        if ($recordModel && is_array($recordModel->spec_json)) {
            $spec = $recordModel->spec_json;
        } else {
            // Anchor generator from fixed seed
            $spec = $this->generator->generateFromSeed($seed);
            AvatarCharacter::create([
                'character_id' => $characterId,
                'spec_json' => $spec,
            ]);
        }

        $fingerprint = CharacterGenerator::fingerprint($spec);

        // In Mode B: face and outfit stay locked, but the run's style varies with $seed!
        $styleSpec = PresenterStyleGenerator::generate(new AvatarPrng($seed), $variationLevel);
        $styleFingerprint = PresenterStyleGenerator::styleFingerprint($styleSpec);

        if ($record) {
            VariationHistory::create([
                'run_id' => $runId,
                'seed' => $seed,
                'variation_fingerprint' => $fingerprint,
                'categories_json' => array_merge($spec, ['_style' => $styleSpec]),
            ]);
        }

        return [
            'character_id' => $characterId,
            'spec' => $spec,
            'fingerprint' => $fingerprint,
            'style_spec' => $styleSpec,
            'style_fingerprint' => $styleFingerprint,
            'seed' => $seed,
            'attempts' => 1,
            'mode' => 'fixed',
        ];
    }

    /**
     * Inspect uniqueness of a candidate spec against the history window.
     *
     * @return array{unique: bool, min_distance: int, closest_fingerprint: ?string, history_count: int}
     */
    public function checkUniqueness(array $candidateSpec, int $window = 200, int $minDiff = 4): array
    {
        $history = VariationHistory::query()
            ->latest('id')
            ->limit($window)
            ->get();

        $minDistance = 999;
        $closestFingerprint = null;

        foreach ($history as $past) {
            if (is_array($past->categories_json)) {
                $pastSpec = array_filter(
                    $past->categories_json,
                    fn ($k) => ! str_starts_with((string) $k, '_'),
                    ARRAY_FILTER_USE_KEY
                );
                $dist = CharacterGenerator::categoryDistance($candidateSpec, $pastSpec);
                if ($dist < $minDistance) {
                    $minDistance = $dist;
                    $closestFingerprint = $past->variation_fingerprint;
                }
            }
        }

        $historyCount = $history->count();
        $isUnique = $historyCount === 0 || $minDistance >= $minDiff;

        return [
            'unique' => $isUnique,
            'min_distance' => $historyCount === 0 ? 12 : $minDistance,
            'closest_fingerprint' => $closestFingerprint,
            'history_count' => $historyCount,
        ];
    }
}
