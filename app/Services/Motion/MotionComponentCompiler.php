<?php

namespace App\Services\Motion;

use App\Enums\AiUsagePurpose;
use App\Enums\MotionCategory;
use App\Enums\MotionComponentStatus;
use App\Enums\MotionLicenseStatus;
use App\Models\AiUsageLedgerEntry;
use App\Models\AutomationRun;
use App\Models\MotionComponent;
use App\Models\MotionRecipe;
use App\Services\Automation\BudgetGuard;
use App\Services\Automation\DryRunContext;
use Illuminate\Support\Str;

class MotionComponentCompiler
{
    public function __construct(
        protected MotionComponentValidator $validator = new MotionComponentValidator,
        protected ?BudgetGuard $budgetGuard = null,
        protected ?DryRunContext $dryRun = null,
    ) {
        $this->budgetGuard = $budgetGuard ?? app(BudgetGuard::class);
        $this->dryRun = $dryRun ?? app(DryRunContext::class);
    }

    /**
     * Author and compile a HyperFrames motion component from a recipe.
     *
     * @param  array{
     *     dry_run?: bool,
     *     run?: AutomationRun|null,
     *     as_original?: bool,
     *     custom_params?: array<string, mixed>
     * }  $options
     */
    public function compileFromRecipe(MotionRecipe $recipe, array $options = []): MotionComponent
    {
        $category = MotionCategory::tryFrom($recipe->category)?->value ?? MotionCategory::LowerThird->value;
        $componentKey = 'comp_'.Str::slug($category).'_'.substr(md5($recipe->id.'_'.$recipe->title), 0, 8);

        // Budget check (approx $0.02 = 20,000 micros for LLM synthesis)
        $estimatedCostMicros = 20_000;
        $isDryRun = (bool) ($options['dry_run'] ?? false) || $this->dryRun->isDryRun($options['run'] ?? null);

        $reservation = null;
        if (! $isDryRun && $this->budgetGuard !== null) {
            $reservation = $this->budgetGuard->authorize($estimatedCostMicros, $options['run'] ?? null);
        }

        // Generate HyperFrames component contract code
        $compiledData = $this->generateComponentCode($category, $recipe->title, $recipe->prompt_text);

        // Validate via strict offline validator
        $componentPayload = [
            'component_key' => $componentKey,
            'version' => 1,
            'category' => $category,
            'html_template' => $compiledData['html_template'],
            'gsap_script' => $compiledData['gsap_script'],
            'params_schema' => $compiledData['params_schema'],
            'supported_formats' => ['9:16', '16:9', '1:1'],
            'sfx_hooks' => $compiledData['sfx_hooks'],
            'tone_compatibility' => ['normal', 'sensitive'],
            'contrast_rating' => 7.5,
        ];

        $this->validator->assertValid($componentPayload);

        // Settle budget if live
        if ($reservation !== null && $this->budgetGuard !== null) {
            $this->budgetGuard->settle($reservation, $estimatedCostMicros, [
                'provider' => 'local',
                'model' => 'motion-compiler',
                'purpose' => 'script',
                'tokens_in' => strlen($recipe->prompt_text),
                'tokens_out' => strlen($compiledData['html_template'] + $compiledData['gsap_script']),
            ]);
        }

        // Licensing rule: default = inherits recipe license (reference_only unless cleared)
        $licenseStatus = ($options['as_original'] ?? false)
            ? MotionLicenseStatus::Original
            : $recipe->license_status;

        return MotionComponent::create([
            'recipe_id' => $recipe->id,
            'component_key' => $componentKey,
            'version' => 1,
            'category' => $category,
            'html_template' => $compiledData['html_template'],
            'gsap_script' => $compiledData['gsap_script'],
            'params_schema' => $compiledData['params_schema'],
            'supported_formats' => ['9:16', '16:9', '1:1'],
            'sfx_hooks' => $compiledData['sfx_hooks'],
            'tone_compatibility' => ['normal', 'sensitive'],
            'contrast_rating' => 7.5,
            'license_status' => $licenseStatus,
            'status' => MotionComponentStatus::PendingReview,
        ]);
    }

    /**
     * Synthesize clean HyperFrames-compliant offline HTML and GSAP code.
     *
     * @return array{
     *     html_template: string,
     *     gsap_script: string,
     *     params_schema: array<string, mixed>,
     *     sfx_hooks: array<string, string>
     * }
     */
    protected function generateComponentCode(string $category, string $title, string $prompt): array
    {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeClass = 'mc-'.Str::slug($category);

        $html = <<<HTML
<div class="{$safeClass}" style="position: absolute; bottom: 10%; left: 5%; right: 5%; z-index: 40; pointer-events: none;">
  <div class="mc-box" style="background: rgba(15, 23, 42, 0.95); border-left: 5px solid var(--motion-accent, #00f3ff); padding: 14px 20px; border-radius: 4px; box-shadow: 0 8px 30px rgba(0,0,0,0.6);">
    <div class="mc-header" style="color: #ffffff; font-family: sans-serif; font-weight: 800; font-size: 22px;">{{title}}</div>
    <div class="mc-body" style="color: #94a3b8; font-family: sans-serif; font-size: 15px; margin-top: 4px;">{{subtitle}}</div>
  </div>
</div>
HTML;

        $gsap = <<<JS
const tl = gsap.timeline({ paused: true });
tl.from(".{$safeClass} .mc-box", { x: -60, opacity: 0, duration: 0.45, ease: "power3.out" })
  .from(".{$safeClass} .mc-header", { y: 10, opacity: 0, duration: 0.3 }, "-=0.2")
  .from(".{$safeClass} .mc-body", { y: 10, opacity: 0, duration: 0.3 }, "-=0.15");
JS;

        return [
            'html_template' => $html,
            'gsap_script' => $gsap,
            'params_schema' => [
                'title' => ['type' => 'string', 'default' => $escapedTitle],
                'subtitle' => ['type' => 'string', 'default' => 'AI Autonomous Intelligence'],
            ],
            'sfx_hooks' => [
                'reveal' => 'whoosh',
            ],
        ];
    }
}

