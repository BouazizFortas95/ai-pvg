<?php

namespace App\Services\Motion;

use App\Enums\MotionCategory;
use App\Enums\MotionComponentStatus;
use App\Enums\MotionLicenseStatus;
use App\Models\MotionComponent;
use Illuminate\Database\Eloquent\Collection;

class MotionLibrary
{
    public function __construct(
        protected MotionComponentValidator $validator = new MotionComponentValidator,
    ) {}

    /**
     * Retrieve approved and renderable components matching category, format, and tone.
     * Enforces the taxonomy rule: reference_only components are excluded.
     *
     * @return Collection<int, MotionComponent>
     */
    public function findForSelection(
        string $category,
        string $format = '9:16',
        string $tone = 'normal',
    ): Collection {
        return MotionComponent::query()
            ->where('category', $category)
            ->where('status', MotionComponentStatus::Approved->value)
            ->whereIn('license_status', [
                MotionLicenseStatus::Original->value,
                MotionLicenseStatus::Cleared->value,
            ])
            ->whereJsonContains('supported_formats', $format)
            ->whereJsonContains('tone_compatibility', $tone)
            ->get();
    }

    /**
     * Seed or update the 13 in-house original starter components.
     *
     * @return int Count of starter components seeded
     */
    public function seedStarterComponents(): int
    {
        $starters = self::starterDefinitions();
        $count = 0;

        foreach ($starters as $data) {
            $this->validator->assertValid($data);

            MotionComponent::updateOrCreate(
                ['component_key' => $data['component_key']],
                [
                    'version' => $data['version'] ?? 1,
                    'category' => $data['category'],
                    'html_template' => $data['html_template'],
                    'gsap_script' => $data['gsap_script'],
                    'params_schema' => $data['params_schema'],
                    'supported_formats' => $data['supported_formats'],
                    'sfx_hooks' => $data['sfx_hooks'] ?? null,
                    'tone_compatibility' => $data['tone_compatibility'],
                    'contrast_rating' => $data['contrast_rating'],
                    'license_status' => MotionLicenseStatus::Original,
                    'status' => MotionComponentStatus::Approved,
                    'approved_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * 13 In-House Original Component Definitions (one per category).
     *
     * @return list<array<string, mixed>>
     */
    public static function starterDefinitions(): array
    {
        return [
            // 1. Lower Third
            [
                'component_key' => 'starter_lower_third_cyber',
                'version' => 1,
                'category' => MotionCategory::LowerThird->value,
                'html_template' => <<<'HTML'
<div class="motion-lower-third" style="position: absolute; bottom: 12%; left: 6%; right: 6%; display: flex; flex-direction: column; z-index: 40; pointer-events: none;">
  <div class="lt-bar" style="background: rgba(10, 16, 26, 0.92); border-left: 6px solid var(--motion-accent, #00f3ff); padding: 12px 18px; border-radius: 4px; box-shadow: 0 8px 32px rgba(0,0,0,0.6); backdrop-filter: blur(12px);">
    <div class="lt-title" style="color: #ffffff; font-family: sans-serif; font-weight: 800; font-size: 24px; text-transform: uppercase; letter-spacing: 0.05em;">{{title}}</div>
    <div class="lt-subtitle" style="color: var(--motion-accent, #00f3ff); font-family: sans-serif; font-size: 16px; margin-top: 4px; font-weight: 500;">{{subtitle}}</div>
  </div>
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.from(".motion-lower-third .lt-bar", { x: -80, opacity: 0, duration: 0.45, ease: "power3.out" })
  .from(".motion-lower-third .lt-title", { opacity: 0, y: 10, duration: 0.3 }, "-=0.2")
  .from(".motion-lower-third .lt-subtitle", { opacity: 0, y: 10, duration: 0.3 }, "-=0.15")
  .to(".motion-lower-third .lt-bar", { opacity: 0, y: -20, duration: 0.4, ease: "power2.in" }, "+=2.5");
JS,
                'params_schema' => [
                    'title' => ['type' => 'string', 'default' => 'BREAKING DISCOVERY'],
                    'subtitle' => ['type' => 'string', 'default' => 'Global Technology Intelligence'],
                ],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'sfx_hooks' => ['reveal' => 'whoosh', 'hide' => 'click'],
                'tone_compatibility' => ['normal', 'sensitive', 'tragic'],
                'contrast_rating' => 7.8,
            ],

            // 2. Headline Bar
            [
                'component_key' => 'starter_headline_bar_pulse',
                'version' => 1,
                'category' => MotionCategory::HeadlineBar->value,
                'html_template' => <<<'HTML'
<div class="motion-headline-bar" style="position: absolute; top: 8%; left: 5%; right: 5%; z-index: 50; pointer-events: none;">
  <div class="hb-pill" style="display: inline-flex; align-items: center; background: #ff0055; color: #ffffff; font-weight: 900; font-size: 14px; padding: 6px 14px; border-radius: 4px; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px;">
    <span class="hb-dot" style="width: 8px; height: 8px; background: #ffffff; border-radius: 50%; margin-right: 8px;"></span>
    {{badge_text}}
  </div>
  <div class="hb-main" style="background: rgba(13, 17, 23, 0.95); color: #f0f6fc; font-family: sans-serif; font-size: 28px; font-weight: 800; line-height: 1.25; padding: 14px 20px; border-radius: 6px; border: 1px solid rgba(255, 0, 85, 0.4); box-shadow: 0 10px 30px rgba(0,0,0,0.7);">
    {{headline}}
  </div>
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.from(".motion-headline-bar .hb-pill", { scale: 0.7, opacity: 0, duration: 0.35, ease: "back.out(1.7)" })
  .from(".motion-headline-bar .hb-main", { y: -30, opacity: 0, duration: 0.45, ease: "power3.out" }, "-=0.15")
  .to(".motion-headline-bar .hb-dot", { opacity: 0.3, repeat: 5, yoyo: true, duration: 0.25 });
JS,
                'params_schema' => [
                    'badge_text' => ['type' => 'string', 'default' => 'SPECIAL REPORT'],
                    'headline' => ['type' => 'string', 'default' => 'New Autonomous Breakthrough Unveiled'],
                ],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'sfx_hooks' => ['reveal' => 'impact'],
                'tone_compatibility' => ['normal', 'sensitive'],
                'contrast_rating' => 8.4,
            ],

            // 3. Ticker
            [
                'component_key' => 'starter_ticker_stream',
                'version' => 1,
                'category' => MotionCategory::Ticker->value,
                'html_template' => <<<'HTML'
<div class="motion-ticker" style="position: absolute; bottom: 0; left: 0; right: 0; height: 44px; background: #090d16; border-top: 2px solid #00f3ff; display: flex; align-items: center; overflow: hidden; z-index: 60; font-family: monospace;">
  <div class="ticker-label" style="background: #00f3ff; color: #050a14; font-weight: 900; font-size: 13px; padding: 0 16px; height: 100%; display: flex; align-items: center; letter-spacing: 0.1em; white-space: nowrap; z-index: 2;">AI-PVG WIRE</div>
  <div class="ticker-content" style="display: flex; white-space: nowrap; color: #e6edf3; font-size: 15px; padding-left: 20px;">
    {{ticker_items}}
  </div>
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.from(".motion-ticker", { y: 44, duration: 0.4, ease: "power2.out" })
  .to(".motion-ticker .ticker-content", { xPercent: -50, duration: 12.0, ease: "none" });
JS,
                'params_schema' => [
                    'ticker_items' => ['type' => 'string', 'default' => '+++ TECH NEWS FLASH +++ GLOBAL MARKETS RALLY +++ ADVANCED AI REASONING GAINS TRACED +++'],
                ],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'sfx_hooks' => ['reveal' => 'whoosh'],
                'tone_compatibility' => ['normal', 'sensitive', 'tragic'],
                'contrast_rating' => 9.2,
            ],

            // 4. Stat Counter
            [
                'component_key' => 'starter_stat_counter_metric',
                'version' => 1,
                'category' => MotionCategory::StatCounter->value,
                'html_template' => <<<'HTML'
<div class="motion-stat-counter" style="position: absolute; top: 40%; left: 10%; right: 10%; background: rgba(15, 23, 42, 0.95); border: 1px solid #38bdf8; border-radius: 8px; padding: 24px; text-align: center; z-index: 45;">
  <div class="stat-number" style="font-size: 56px; font-weight: 900; color: #38bdf8; font-family: sans-serif;">{{target_value}}</div>
  <div class="stat-label" style="font-size: 18px; color: #f1f5f9; margin-top: 6px; font-weight: 600;">{{label}}</div>
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.from(".motion-stat-counter", { scale: 0.85, opacity: 0, duration: 0.4, ease: "back.out(1.5)" })
  .from(".motion-stat-counter .stat-number", { y: 20, opacity: 0, duration: 0.35 }, "-=0.2");
JS,
                'params_schema' => [
                    'target_value' => ['type' => 'string', 'default' => '+94.2%'],
                    'label' => ['type' => 'string', 'default' => 'Efficiency Improvement Recorded'],
                ],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'sfx_hooks' => ['reveal' => 'impact'],
                'tone_compatibility' => ['normal', 'sensitive'],
                'contrast_rating' => 6.5,
            ],

            // 5. Quote Card
            [
                'component_key' => 'starter_quote_card_glass',
                'version' => 1,
                'category' => MotionCategory::QuoteCard->value,
                'html_template' => <<<'HTML'
<div class="motion-quote-card" style="position: absolute; top: 32%; left: 8%; right: 8%; background: rgba(17, 24, 39, 0.94); border-left: 6px solid #a855f7; border-radius: 6px; padding: 24px 28px; z-index: 45; box-shadow: 0 12px 36px rgba(0,0,0,0.7);">
  <div class="quote-mark" style="color: #a855f7; font-size: 40px; line-height: 1; font-family: serif; margin-bottom: 8px;">“</div>
  <div class="quote-text" style="color: #f3f4f6; font-size: 22px; font-style: italic; line-height: 1.4; font-family: sans-serif;">{{quote}}</div>
  <div class="quote-author" style="color: #c084fc; font-size: 16px; margin-top: 14px; font-weight: 700; text-align: right;">— {{author}}</div>
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.from(".motion-quote-card", { opacity: 0, y: 30, duration: 0.5, ease: "power3.out" })
  .from(".motion-quote-card .quote-mark", { scale: 0, duration: 0.3, ease: "back.out" }, "-=0.2")
  .from(".motion-quote-card .quote-text", { opacity: 0, duration: 0.4 }, "-=0.15");
JS,
                'params_schema' => [
                    'quote' => ['type' => 'string', 'default' => 'The next era of autonomy will redefine how intelligence is deployed.'],
                    'author' => ['type' => 'string', 'default' => 'Lead Research Scientist'],
                ],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'sfx_hooks' => ['reveal' => 'whoosh'],
                'tone_compatibility' => ['normal', 'sensitive', 'tragic'],
                'contrast_rating' => 7.2,
            ],

            // 6. Map Callout
            [
                'component_key' => 'starter_map_callout_radar',
                'version' => 1,
                'category' => MotionCategory::MapCallout->value,
                'html_template' => <<<'HTML'
<div class="motion-map-callout" style="position: absolute; top: 45%; left: 50%; transform: translate(-50%, -50%); z-index: 45; text-align: center;">
  <div class="radar-ping" style="width: 24px; height: 24px; background: #22c55e; border-radius: 50%; margin: 0 auto; box-shadow: 0 0 16px #22c55e;"></div>
  <div class="callout-box" style="margin-top: 12px; background: rgba(15, 23, 42, 0.95); border: 1px solid #22c55e; color: #ffffff; padding: 10px 18px; border-radius: 4px; font-weight: 800; font-size: 18px; white-space: nowrap;">
    {{location_name}}
  </div>
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.from(".motion-map-callout .radar-ping", { scale: 0, duration: 0.4, ease: "elastic.out(1, 0.5)" })
  .from(".motion-map-callout .callout-box", { opacity: 0, y: 15, duration: 0.35 }, "-=0.1");
JS,
                'params_schema' => [
                    'location_name' => ['type' => 'string', 'default' => 'Tokyo, Japan · Global Lab'],
                ],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'sfx_hooks' => ['reveal' => 'click'],
                'tone_compatibility' => ['normal', 'sensitive'],
                'contrast_rating' => 8.0,
            ],

            // 7. Icon Callout
            [
                'component_key' => 'starter_icon_callout_badge',
                'version' => 1,
                'category' => MotionCategory::IconCallout->value,
                'html_template' => <<<'HTML'
<div class="motion-icon-callout" style="position: absolute; top: 25%; right: 8%; display: flex; align-items: center; background: rgba(24, 24, 27, 0.94); border: 1px solid #eab308; padding: 10px 16px; border-radius: 24px; z-index: 45;">
  <div class="icon-circle" style="width: 28px; height: 28px; background: #eab308; color: #000; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 16px; margin-right: 10px;">★</div>
  <div class="callout-text" style="color: #fef08a; font-weight: 700; font-size: 15px;">{{callout_label}}</div>
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.from(".motion-icon-callout", { x: 40, opacity: 0, duration: 0.4, ease: "power2.out" })
  .from(".motion-icon-callout .icon-circle", { rotation: -180, duration: 0.4 }, "-=0.3");
JS,
                'params_schema' => [
                    'callout_label' => ['type' => 'string', 'default' => 'VERIFIED INSIGHT'],
                ],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'sfx_hooks' => ['reveal' => 'snap'],
                'tone_compatibility' => ['normal', 'sensitive'],
                'contrast_rating' => 7.5,
            ],

            // 8. Kinetic Typography
            [
                'component_key' => 'starter_kinetic_type_punch',
                'version' => 1,
                'category' => MotionCategory::KineticType->value,
                'html_template' => <<<'HTML'
<div class="motion-kinetic-type" style="position: absolute; top: 40%; left: 8%; right: 8%; text-align: center; z-index: 45;">
  <div class="kt-word" style="display: inline-block; font-size: 44px; font-weight: 900; color: #ffffff; text-transform: uppercase; letter-spacing: 0.04em; text-shadow: 0 4px 20px rgba(0,0,0,0.8);">
    {{punch_word}}
  </div>
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.from(".motion-kinetic-type .kt-word", { scale: 2.2, opacity: 0, duration: 0.35, ease: "power4.out" })
  .to(".motion-kinetic-type .kt-word", { scale: 1.05, duration: 0.15, yoyo: true, repeat: 1 });
JS,
                'params_schema' => [
                    'punch_word' => ['type' => 'string', 'default' => 'REVOLUTIONARY'],
                ],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'sfx_hooks' => ['reveal' => 'impact'],
                'tone_compatibility' => ['normal'],
                'contrast_rating' => 9.0,
            ],

            // 9. Transition
            [
                'component_key' => 'starter_transition_wipe',
                'version' => 1,
                'category' => MotionCategory::Transition->value,
                'html_template' => <<<'HTML'
<div class="motion-transition-wipe" style="position: absolute; inset: 0; z-index: 80; pointer-events: none; overflow: hidden;">
  <div class="wipe-curtain" style="position: absolute; inset: 0; background: #00f3ff; transform: translateX(-100%);"></div>
  <div class="wipe-curtain-dark" style="position: absolute; inset: 0; background: #0b0f19; transform: translateX(-100%);"></div>
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.to(".motion-transition-wipe .wipe-curtain", { xPercent: 100, duration: 0.45, ease: "power2.inOut" })
  .to(".motion-transition-wipe .wipe-curtain-dark", { xPercent: 100, duration: 0.45, ease: "power2.inOut" }, "-=0.35");
JS,
                'params_schema' => [],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'sfx_hooks' => ['reveal' => 'whoosh'],
                'tone_compatibility' => ['normal', 'sensitive', 'tragic'],
                'contrast_rating' => 9.5,
            ],

            // 10. Animated Background
            [
                'component_key' => 'starter_animated_bg_grid',
                'version' => 1,
                'category' => MotionCategory::AnimatedBackground->value,
                'html_template' => <<<'HTML'
<div class="motion-animated-bg" style="position: absolute; inset: 0; z-index: 1; pointer-events: none; opacity: 0.25; overflow: hidden;">
  <div class="bg-grid-lines" style="position: absolute; inset: -20%; background-image: linear-gradient(to right, rgba(0, 243, 255, 0.15) 1px, transparent 1px), linear-gradient(to bottom, rgba(0, 243, 255, 0.15) 1px, transparent 1px); background-size: 60px 60px;"></div>
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.to(".motion-animated-bg .bg-grid-lines", { y: 60, duration: 8.0, repeat: -1, ease: "none" });
JS,
                'params_schema' => [],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'tone_compatibility' => ['normal', 'sensitive', 'tragic'],
                'contrast_rating' => 8.0,
            ],

            // 11. Stinger
            [
                'component_key' => 'starter_stinger_flare',
                'version' => 1,
                'category' => MotionCategory::Stinger->value,
                'html_template' => <<<'HTML'
<div class="motion-stinger" style="position: absolute; inset: 0; z-index: 90; display: flex; align-items: center; justify-content: center; background: rgba(5, 8, 16, 0.95);">
  <div class="stinger-logo" style="color: #00f3ff; font-size: 42px; font-weight: 900; letter-spacing: 0.12em; text-transform: uppercase;">
    {{channel_name}}
  </div>
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.from(".motion-stinger .stinger-logo", { scale: 0.6, opacity: 0, duration: 0.45, ease: "back.out(1.8)" })
  .to(".motion-stinger", { opacity: 0, duration: 0.4, ease: "power2.in" }, "+=0.8");
JS,
                'params_schema' => [
                    'channel_name' => ['type' => 'string', 'default' => 'AI-PVG BROADCAST'],
                ],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'sfx_hooks' => ['reveal' => 'impact'],
                'tone_compatibility' => ['normal'],
                'contrast_rating' => 9.0,
            ],

            // 12. Source Badge
            [
                'component_key' => 'starter_source_badge_verified',
                'version' => 1,
                'category' => MotionCategory::SourceBadge->value,
                'html_template' => <<<'HTML'
<div class="motion-source-badge" style="position: absolute; bottom: 8%; right: 6%; background: rgba(15, 23, 42, 0.92); border: 1px solid #10b981; border-radius: 20px; padding: 6px 14px; display: inline-flex; align-items: center; z-index: 55;">
  <span style="color: #10b981; font-weight: 900; margin-right: 6px;">✓</span>
  <span style="color: #f1f5f9; font-size: 13px; font-weight: 600;">{{source_name}}</span>
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.from(".motion-source-badge", { opacity: 0, y: 15, duration: 0.4, ease: "power2.out" });
JS,
                'params_schema' => [
                    'source_name' => ['type' => 'string', 'default' => 'Source: Primary Wire & Tech Docs'],
                ],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'tone_compatibility' => ['normal', 'sensitive', 'tragic'],
                'contrast_rating' => 7.8,
            ],

            // 13. AI Disclosure Badge
            [
                'component_key' => 'starter_ai_disclosure_watermark',
                'version' => 1,
                'category' => MotionCategory::AiDisclosureBadge->value,
                'html_template' => <<<'HTML'
<div class="motion-ai-disclosure" style="position: absolute; top: 3%; right: 5%; background: rgba(0, 0, 0, 0.65); border-radius: 4px; padding: 4px 10px; font-size: 11px; color: rgba(255, 255, 255, 0.85); font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; z-index: 95; backdrop-filter: blur(4px);">
  AI-GENERATED MEDIA
</div>
HTML,
                'gsap_script' => <<<'JS'
const tl = gsap.timeline({ paused: true });
tl.from(".motion-ai-disclosure", { opacity: 0, duration: 0.5, ease: "power1.in" });
JS,
                'params_schema' => [],
                'supported_formats' => ['9:16', '16:9', '1:1'],
                'tone_compatibility' => ['normal', 'sensitive', 'tragic'],
                'contrast_rating' => 8.5,
            ],
        ];
    }
}

