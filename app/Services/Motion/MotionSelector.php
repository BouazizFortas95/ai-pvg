<?php

namespace App\Services\Motion;

use App\Enums\MotionCategory;
use App\Enums\MotionComponentStatus;
use App\Enums\MotionLicenseStatus;
use App\Models\MotionComponent;
use App\Models\MotionComponentUsage;
use App\Models\PipelineRun;
use App\Services\Avatar\AvatarPrng;
use Illuminate\Support\Collection;

class MotionSelector
{
    protected AvatarPrng $prng;

    /**
     * @param  int  $seed  PRNG seed for deterministic selection
     * @param  string  $tone  Content tone: 'normal' | 'sensitive' | 'tragic'
     * @param  string  $format  Aspect ratio: '9:16' | '16:9' | '1:1'
     * @param  array<string, string>  $locks  Explicitly locked components [category => component_key] or [scene_index => component_key]
     */
    public function __construct(
        protected int $seed,
        protected string $tone = 'normal',
        protected string $format = '9:16',
        protected array $locks = [],
        protected MotionLibrary $library = new MotionLibrary,
    ) {
        $this->prng = new AvatarPrng($seed);
    }

    /**
     * Select motion components for an array of scenes from approved and cleared/original IP only.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @param  PipelineRun|null  $run
     * @return array{
     *     selections: list<array<string, mixed>>,
     *     fingerprint: string,
     *     seed: int,
     *     format: string,
     *     tone: string
     * }
     */
    public function selectForScenes(array $scenes, ?PipelineRun $run = null): array
    {
        $selections = [];
        $usedKeys = [];

        foreach ($scenes as $index => $scene) {
            $sceneIndex = $index + 1;
            $category = $this->determineCategoryForScene($scene, $sceneIndex, count($scenes));

            // Check if user locked a specific component
            $lockedKey = $this->locks[$category] ?? $this->locks[$sceneIndex] ?? null;
            $component = null;

            if ($lockedKey) {
                $component = MotionComponent::query()
                    ->where('component_key', $lockedKey)
                    ->where('status', MotionComponentStatus::Approved->value)
                    ->whereIn('license_status', [
                        MotionLicenseStatus::Original->value,
                        MotionLicenseStatus::Cleared->value,
                    ])
                    ->first();
            }

            if ($component === null) {
                $candidates = $this->getCandidates($category);

                if ($candidates->isNotEmpty()) {
                    /** @var MotionComponent $component */
                    $component = $this->prng->pick($candidates->all());
                }
            }

            if ($component === null) {
                // Fallback: pick any renderable component in the library
                $fallbackCandidates = MotionComponent::query()
                    ->where('status', MotionComponentStatus::Approved->value)
                    ->whereIn('license_status', [
                        MotionLicenseStatus::Original->value,
                        MotionLicenseStatus::Cleared->value,
                    ])
                    ->whereJsonContains('supported_formats', $this->format)
                    ->get();

                if ($fallbackCandidates->isNotEmpty()) {
                    /** @var MotionComponent $component */
                    $component = $this->prng->pick($fallbackCandidates->all());
                }
            }

            if ($component !== null) {
                $appliedParams = $this->buildAppliedParams($component, $scene);

                // Record usage in database
                if ($run !== null) {
                    MotionComponentUsage::create([
                        'pipeline_run_id' => $run->id,
                        'motion_component_id' => $component->id,
                        'scene_index' => $sceneIndex,
                        'applied_params' => $appliedParams,
                        'seed' => $this->seed,
                        'created_at' => now(),
                    ]);
                }

                $usedKeys[] = $component->component_key;

                $selections[] = [
                    'scene_index' => $sceneIndex,
                    'category' => $component->category,
                    'component_id' => $component->id,
                    'component_key' => $component->component_key,
                    'version' => $component->version,
                    'license_status' => $component->license_status->value,
                    'applied_params' => $appliedParams,
                    'html_template' => $component->html_template,
                    'gsap_script' => $component->gsap_script,
                    'sfx_hooks' => $component->sfx_hooks,
                ];
            }
        }

        $fingerprint = hash('sha256', implode('|', [
            implode(',', $usedKeys),
            $this->seed,
            $this->format,
            $this->tone,
        ]));

        return [
            'selections' => $selections,
            'fingerprint' => $fingerprint,
            'seed' => $this->seed,
            'format' => $this->format,
            'tone' => $this->tone,
        ];
    }

    /**
     * Get candidate components for category honoring tone and format.
     *
     * @return Collection<int, MotionComponent>
     */
    protected function getCandidates(string $category): Collection
    {
        $query = MotionComponent::query()
            ->where('category', $category)
            ->where('status', MotionComponentStatus::Approved->value)
            ->whereIn('license_status', [
                MotionLicenseStatus::Original->value,
                MotionLicenseStatus::Cleared->value,
            ])
            ->whereJsonContains('supported_formats', $this->format);

        // Restrict tone compatibility
        $query->whereJsonContains('tone_compatibility', $this->tone);

        return $query->get();
    }

    /**
     * Determine motion category for a scene based on edit plan directives or flow.
     */
    protected function determineCategoryForScene(array $scene, int $sceneIndex, int $totalScenes): string
    {
        // 1. Directives priority
        if (! empty($scene['directives']['motion_slot'])) {
            $slot = (string) $scene['directives']['motion_slot'];
            if (MotionCategory::tryFrom($slot) !== null) {
                return $slot;
            }
        }

        // 2. Sensitive / tragic tone restrictions (subdued motion only)
        if (in_array($this->tone, ['sensitive', 'tragic'], true)) {
            $subdued = [
                MotionCategory::LowerThird->value,
                MotionCategory::QuoteCard->value,
                MotionCategory::Ticker->value,
                MotionCategory::SourceBadge->value,
            ];

            return $subdued[($sceneIndex - 1) % count($subdued)];
        }

        // 3. Dynamic arc for normal tone
        if ($sceneIndex === 1) {
            return MotionCategory::HeadlineBar->value;
        }

        if ($sceneIndex === $totalScenes) {
            return MotionCategory::SourceBadge->value;
        }

        $midCycle = [
            MotionCategory::LowerThird->value,
            MotionCategory::StatCounter->value,
            MotionCategory::KineticType->value,
            MotionCategory::IconCallout->value,
            MotionCategory::QuoteCard->value,
        ];

        return $midCycle[($sceneIndex - 2) % count($midCycle)];
    }

    /**
     * Interpolate params from scene text and schema defaults.
     *
     * @return array<string, mixed>
     */
    protected function buildAppliedParams(MotionComponent $component, array $scene): array
    {
        $schema = $component->params_schema ?? [];
        $params = [];

        foreach ($schema as $key => $spec) {
            $default = $spec['default'] ?? '';

            if ($key === 'title' || $key === 'headline') {
                $params[$key] = (string) ($scene['headline'] ?? $scene['narration'] ?? $default);
            } elseif ($key === 'subtitle') {
                $params[$key] = (string) ($scene['narration'] ?? $default);
            } else {
                $params[$key] = $default;
            }
        }

        return $params;
    }
}

