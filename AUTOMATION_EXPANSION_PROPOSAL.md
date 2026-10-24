# AI-PVG — Hyper-Automation & Autonomous Intelligence Expansion Plan

**File:** `AUTOMATION_EXPANSION_PROPOSAL.md`
**Status:** G-00 approved/verified; G-01 and G-02 implemented and verified; G-03+ proposed.
**Author role:** Principal AI Systems Architect / Lead Laravel 12 Engineer / Media Automation Strategist
**Date:** 2026-10-24
**Baseline:** Tasks 01–10.6 · G-00 verified · G-01 verified · G-02 verified 2026-10-24 — 205 tests / 883 assertions / 0 failures / 0 risky.

> **Scope statement:** This document defines the modular Phase G and Phase H roadmap. G-01 and G-02 are implemented dark (`AUTOMATION_ENABLED=false`, `AUTOMATION_DRY_RUN=true`, `AUTOMATION_TRIAGE_ENABLED=false`); G-03+ remains proposed. Every automation described here is opt-in, config-gated, dry-run-capable, budget-bounded, and HITL-aware.

---

## 1. Executive Summary

AI-PVG today is a functional **semi-automated** pipeline:

```
RSS → News → Duplicate Detection → AI Script → TTS Voice → Storyboard
    → Remotion Render → Export Validation → YouTube Upload (opt-in)
```

The expansion plan turns it into a **self-driving, high-retention media engine** by adding an automation control plane and eight intelligence pillars:

| # | Pillar | Primary Automation Outcome |
|---|--------|----------------------------|
| 1 | Autonomous News Scoring & Virality Triage | Only stories with measurable velocity/novelty reach script generation |
| 2 | Retention-Engineered AI Script & Storyboard | Multi-agent hook → narrative → fact-check chain with scene directives |
| 3 | Word-Level Audio Sync, Captions & Sound Design | Kinetic word captions, auto-ducking, transition SFX via FFmpeg |
| 4 | Autonomous Visual Asset & B-Roll Acquisition | Licensed entity/logo/stock harvesting injected into Remotion props |
| 5 | Multi-Format “One-Render-Many-Outputs” Repurposing | 9:16 + 16:9 + 1:1 variants, thumbnails, A/B metadata |
| 6 | Autonomous QA, Self-Healing & Render Reliability | Preflight, golden renders, bounded retries, quarantine |
| 7 | Performance Feedback & Continuous Optimization | Opt-in retention/CTR signals feed scoring and prompt tuning |
| 8 | Autonomous Orchestration & Distribution Preparation | Pipeline orchestrator, approval inbox, cost/ops dashboards |

**Important:** Pillars 6–8 complete the eight-pillar requirement. The original brief enumerated Pillars 1–4 fully and Pillar 5 partially; Pillars 6–8 below are defined to satisfy the stated “8 high-leverage automation pillars” and can be renamed if the product owner has a different list.

### 1.1 The one non-negotiable prerequisite — RESOLVED

**Task G-00 was completed and verified on 2026-10-24.** The bundle/serve blocker was caused by two issues: the old CLI pointed at a static `serve/index.html` instead of a `@remotion/bundler` output, and a stale `static-serve.cjs` process on port 3000 was reused by Remotion.

Evidence:
- `storage/app/video_out/g00_laravel_verified.mp4` — `h264`, 1080x1920, 30 fps, 6.000 s, 35,384 B.
- `storage/app/video_out/g00_audio_laravel_verified.mp4` — `h264` + `aac` stereo 48 kHz, video 6.000 s / audio 6.016 s, 283,797 B.
- A render also succeeded while port 3000 was deliberately occupied (`g00_port_guard_verified.mp4`).

Pillars 3–8 are still **architecture proposals** until their own integration tests pass, but they are no longer blocked by Remotion.

---

## 2. Current-State Inspection (Code-Grounded)

### 2.1 What already exists

| Layer | Existing assets | Notes |
|-------|-----------------|-------|
| Models | `News`, `NewsCategory`, `NewsSource`, `VideoScript`, `VoiceGeneration`, `VideoRender`, `VideoExport`, `YoutubePublishingState`, `RssImportLog`, `UserThemePreference` | 16 migrations; SQLite-compatible |
| AI | `AIManager`, `OllamaProvider`, `OpenAICompatibleProvider`, `VideoScriptGenerator` | Single-prompt generation only |
| TTS | `TTSManager`, `TTSProviderInterface`, `MockTTSProvider` | Provider-agnostic shell; only mock provider implemented |
| Import | `RssImportService`, `NewsDuplicateService`, `ImportResult`, `ImportRssSourceJob`, `news:import-rss` | 30-minute scheduler already present |
| Render | `VideoRenderingService`, `RenderVideoJob`, `remotion/src/VideoScript.tsx`, `remotion/src/render-cli.ts` | Output is file-existence/size checked; FFprobe optional |
| Export | `VideoExportService`, `PlatformPresetService`, `VideoExport` | Presets: YouTube Shorts, TikTok, Facebook Reels |
| Publishing | `PublishingProviderInterface`, `YouTubePublishingProvider`, `PublishingService`, `YoutubePublishingState` | Resumable upload code; mocked tests only; no real upload |
| Admin | Filament 5 resources for News, NewsSources, VideoScripts, RssImportLogs | `/auth` |
| Studio | `StudioDashboard` Livewire, Bootstrap 5, theme engine | `/studio`, auth-protected |
| Diagnostics | `ai-pvg:diagnostics`, `ai-pvg:verify-mp4` | Read-only checks; verify command opt-in |
| Tests | 130 passed / 360 assertions | Mocked HTTP/process boundaries |

### 2.2 Gaps this plan addresses

1. **No news prioritization** — every imported item is equal; no velocity, novelty, or cross-source clustering.
2. **Single-agent scripting** — one prompt produces hooks, narrative, and fact claims without separation of concerns.
3. **No word-level timings** — TTS returns only a total duration; no kinetic captions.
4. **No audio post-processing** — no ducking, music, or SFX automation.
5. **No visual asset layer** — Remotion props are text/scene-only.
6. **Single-aspect render** — no 9:16/16:9/1:1 fan-out.
7. **No automation control plane** — no run ledger, budget accounting, circuit breaker, or approval inbox.
8. **No feedback loop** — no opt-in retention/CTR signals feeding future prioritization.
9. **Render integration verified** — G-00 produced FFprobe-verified silent and audio-backed MP4s (2026-10-24).

### 2.3 Baseline verification levels (per `PROJECT_STATUS.md` taxonomy)

| Capability | Implemented | Tested | Integration Verified |
|------------|:-----------:|:------:|:--------------------:|
| RSS ingestion | Yes | Yes | Yes (historical) |
| Duplicate detection | Yes | Yes | Yes (historical) |
| AI script (single prompt) | Yes | Mocked | Not verified against live provider |
| TTS | Yes (mock) | Mocked | No real provider verified |
| Remotion render | Yes | Yes | **Yes — FFprobe-verified MP4 (G-00)** |
| Export validation | Yes | Yes | Partially verified — real export run still pending |
| YouTube upload | Yes | Mocked HTTP | No real OAuth/upload |
| Diagnostics | Yes | Yes | Read-only only |

---

## 3. Design Principles & Non-Negotiables

1. **Automation is additive, never destructive.**
   - All migrations additive (`create_*`, `add_*`).
   - No changes to existing columns, enums, routes, or authorization rules without a dedicated migration and rollback plan.
   - Existing manual workflows remain the fallback path.

2. **Dry-run by default for every new AI/media automation.**
   - `AUTOMATION_DRY_RUN=true` is the default in `.env.example` for new variables.
   - Dry-run uses `OllamaProvider` and local/mock TTS where configured; it records intended actions without spending money or publishing.

3. **Budget circuit breakers are mandatory.**
   - Every provider call passes through a `BudgetGuard`.
   - Hard monthly cap + per-run cap + per-call cap.
   - Breaker opens → automation degrades to local fallback or pauses, never silently overspends.

4. **Human-in-the-Loop gates are explicit.**
   - Auto-greenlight may create scripts but never publish.
   - Public visibility requires a separate, explicit, logged approval.
   - Paid provider use above threshold requires an approval gate.

5. **Truthful status model.**
   - `started ≠ completed`, `file exists ≠ verified media`, `uploaded ≠ public`.
   - `verified=false` cannot be promoted to `verified` without a real media check.
   - Existing enums keep their semantics; new statuses are additive.

6. **Reuse before adding.**
   - Extend `AIManager`, `TTSManager`, `VideoScriptGenerator`, `VideoRenderingService`, `VideoExportService`, `PublishingService`.
   - No parallel rendering/export/publishing architecture.

7. **SQLite-first compatibility.**
   - Use Laravel JSON casts, indexed scalar columns, and application-side scoring.
   - Avoid database-specific full-text search, window functions, or JSON path queries.

8. **Idempotency & concurrency safety.**
   - `ShouldBeUnique`, database unique keys, and an `automation_runs` ledger.
   - Retries must not duplicate scripts, voices, renders, exports, or uploads.

9. **Security & privacy.**
   - Reuse `RssImportService::isSafeUrl` SSRF protections for asset harvesting.
   - Encrypt provider credentials (existing `Crypt` pattern).
   - Sanitize logs; never log tokens, OAuth codes, or signed session URLs.

---

## 4. Shared Automation Control Plane (Foundation for All Pillars)

### 4.1 Why a control plane

Eight pillars without a shared run ledger, budget guard, and approval inbox would create eight disconnected automations. The control plane provides one place to observe, pause, budget, and approve every automated action.

### 4.2 New core tables (all additive)

```text
automation_runs
  id, pipeline (string, indexed), subject_type, subject_id (nullable morph),
  status (pending|running|paused|completed|failed|cancelled),
  dry_run (bool), trigger (scheduler|event|manual|auto_greenlight),
  budget_limit_micros, budget_spent_micros,
  started_at, completed_at, error_code, error_message, meta (json),
  created_at, updated_at
  unique: (pipeline, subject_type, subject_id, active_window)  ← idempotency

automation_stage_runs
  id, automation_run_id (fk), stage (string, indexed),
  status (pending|running|completed|failed|skipped),
  attempt (int), max_attempts (int),
  input_ref (json), output_ref (json), cost_micros (int),
  started_at, completed_at, error_message,
  created_at, updated_at
  unique: (automation_run_id, stage, attempt)

ai_usage_ledger
  id, automation_run_id (nullable fk), provider, model,
  purpose (script|score|critic|thumbnail|metadata),
  tokens_in, tokens_out, cost_micros,
  created_at
  index: (created_at), (provider)

budget_periods
  id, period_start, period_end, limit_micros, spent_micros,
  breaker_state (closed|open|half_open), opened_at, reset_at
  unique: period_start

approval_gates
  id, gateable_type, gateable_id, gate_type
    (auto_greenlight|paid_provider|public_publish|asset_license|bulk_render),
  status (pending|approved|rejected|expired),
  requested_by, decided_by (nullable), decision_note,
  expires_at, decided_at, meta (json),
  created_at, updated_at
  index: (status, gate_type)

provider_health_events
  id, provider, event_type (rate_limit|quota|auth|timeout|error),
  http_status, retry_after_seconds, message_sanitized,
  created_at

feature_flags
  id, key, enabled (bool), scope (global|user|pipeline), meta (json),
  created_at, updated_at
  unique: key
```

### 4.3 New services / contracts

| Component | Responsibility |
|-----------|----------------|
| `App\Contracts\AutomationStageInterface` | `run(AutomationContext): StageResult`, `isRetryable()`, `costEstimate()` |
| `App\Services\Automation\AutomationOrchestrator` | Creates runs, executes stages, persists stage results, emits events |
| `App\Services\Automation\BudgetGuard` | Pre-call estimate, post-call accounting, breaker transitions |
| `App\Services\Automation\DryRunContext` | Forces local providers, blocks publish/upload, returns synthetic-but-labeled results |
| `App\Services\Automation\ApprovalService` | Creates/decides/expires gates; enforces role policy |
| `App\Services\Automation\CircuitBreaker` | Closed/open/half-open state per provider and period |
| `App\Services\Automation\PipelineRegistry` | Declares pipelines and their ordered stages (code-defined, config-tunable) |
| `App\Services\Automation\StageResult` | Immutable DTO: status, output, cost, error, retryable |
| `App\Events\Automation\*` | `RunStarted`, `StageCompleted`, `StageFailed`, `BudgetThresholdReached`, `ApprovalRequested`, `ApprovalDecided` |

### 4.4 Config: `config/automation.php`

```php
return [
    'enabled' => env('AUTOMATION_ENABLED', false),
    'dry_run' => env('AUTOMATION_DRY_RUN', true),
    'queue' => [
        'high' => env('AUTOMATION_QUEUE_HIGH', 'automation-high'),
        'media' => env('AUTOMATION_QUEUE_MEDIA', 'media'),
        'default' => env('AUTOMATION_QUEUE_DEFAULT', 'default'),
    ],
    'budget' => [
        'monthly_limit_micros' => (int) env('AUTOMATION_MONTHLY_BUDGET_MICROS', 5_000_000), // $5.00
        'per_run_limit_micros' => (int) env('AUTOMATION_PER_RUN_BUDGET_MICROS', 250_000),   // $0.25
        'per_call_limit_micros' => (int) env('AUTOMATION_PER_CALL_BUDGET_MICROS', 50_000),  // $0.05
        'alert_at_percent' => (int) env('AUTOMATION_BUDGET_ALERT_PERCENT', 80),
    ],
    'providers' => [
        'default' => env('AUTOMATION_AI_PROVIDER', 'ollama'),
        'fallback' => env('AUTOMATION_AI_FALLBACK', 'ollama'),
        'tts' => env('AUTOMATION_TTS_PROVIDER', 'mock'),
    ],
    'news_scoring' => [
        'min_auto_greenlight' => (int) env('AUTOMATION_AUTO_GREENLIGHT_MIN', 85),
        'velocity_window_hours' => (int) env('AUTOMATION_VELOCITY_WINDOW_HOURS', 12),
        'cluster_similarity_threshold' => (float) env('AUTOMATION_CLUSTER_SIMILARITY', 0.82),
        'weights' => [
            'velocity' => 0.35,
            'keyword_heat' => 0.25,
            'novelty' => 0.20,
            'source_diversity' => 0.10,
            'recency' => 0.10,
        ],
    ],
    'render' => [
        'formats' => ['9:16', '16:9'],
        'max_parallel' => (int) env('AUTOMATION_RENDER_PARALLEL', 1),
    ],
    'publishing' => [
        'allow_public_without_dual_approval' => false,
        'default_visibility' => 'private',
    ],
];
```

All environment variable additions are documented in `.env.example` with **placeholders only**.

### 4.5 Approval matrix

| Action | Default gate | Who can approve |
|--------|--------------|-----------------|
| Auto-greenlight script generation | Config threshold (score ≥ 85) | System + optional HITL review |
| Paid LLM call above per-run cap | `paid_provider` gate | Admin |
| Render batch > N videos | `bulk_render` gate | Admin |
| Public YouTube visibility | `public_publish` gate | Admin + second admin (dual control) |
| Stock/asset license exception | `asset_license` gate | Admin |

---

## 5. The Eight Automation Pillars

### Pillar 1 — Autonomous News Scoring & Virality Triage

#### Current gap
`RssImportService` imports every item equally (`app/Services/RssImportService.php`). `GenerateVideoScriptJob` is only dispatched manually for a single `VideoScript`. There is no cross-source aggregation, no velocity calculation, and no auto-greenlight.

#### Proposed architecture

**Tables**

```text
news_scores
  id, news_id (fk, unique), velocity_score, keyword_heat_score,
  novelty_score, source_diversity_score, recency_score,
  composite_score (decimal 5,2, indexed),
  breakdown (json), scored_at, created_at, updated_at

story_clusters
  id, canonical_title, canonical_summary, fact_sheet (json),
  status (open|merged|ready|rejected), confidence (decimal 5,2),
  window_start, window_end, source_count (int),
  created_at, updated_at

story_cluster_news
  id, story_cluster_id (fk), news_id (fk), similarity (decimal 5,4),
  is_primary (bool), unique (story_cluster_id, news_id)
```

**Services**

- `App\Services\Automation\Scoring\NewsScoringService`
  - Computes normalized scores from configurable weights.
  - Velocity: count of distinct sources reporting the same cluster within `velocity_window_hours`.
  - Keyword heat: weighted keyword dictionary (editable in config/DB); no external trend API required in Phase G.
  - Novelty: token/Jaccard similarity against recent 7-day titles; local-only.
  - Recency: exponential decay from `published_at`.
  - Writes `news_scores` with a transparent `breakdown` JSON.

- `App\Services\Automation\Clustering\StoryClusteringService`
  - Local semantic similarity using normalized tokens + cosine/TF-IDF approximation (no paid embeddings required).
  - Optional embedding provider behind `EmbeddingProviderInterface` (Ollama or OpenAI-compatible).
  - Merges 3–5 articles into a `story_clusters` row and produces a **source-attributed fact sheet**:
    ```json
    {
      "claims": [
        {"claim": "...", "sources": [{"news_id": 1, "url": "..."}], "confidence": "high"}
      ],
      "entities": ["OpenAI", "NVIDIA"],
      "timeline": ["2026-10-23T10:00:00Z"],
      "open_questions": ["..."]
    }
    ```
  - Never invents facts; every claim must reference at least one `news_id`.

- `App\Services\Automation\Greenlight\AutoGreenlightService`
  - Evaluates `composite_score >= min_auto_greenlight`.
  - Creates `VideoScript` in `draft` status and dispatches `GenerateVideoScriptJob` on the `automation-high` queue.
  - Writes `approval_gates` row when the score is between a “review” band (e.g., 70–84) — script is created but paused.
  - Honors `AUTOMATION_DRY_RUN`: creates a dry-run `automation_runs` record and a `VideoScript` marked `draft` with `meta.dry_run=true`, but does not call paid providers.

**Jobs / commands**

- `ScoreNewsJob` — batched scoring for unscored news (chunk 100).
- `ClusterStoriesJob` — every 15 minutes; builds/updates clusters.
- `AutoGreenlightJob` — consumes scored clusters above threshold.
- `ai-pvg:automation:tick` — single scheduler entry point that respects `withoutOverlapping()`.

**Scheduler additions** (`routes/console.php`)

```php
Schedule::job(new ScoreNewsJob)->everyTenMinutes()->withoutOverlapping();
Schedule::job(new ClusterStoriesJob)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new AutoGreenlightJob)->everyFifteenMinutes()->withoutOverlapping();
```

**UI**

- Filament: `NewsScoreResource` (read-only score + breakdown), `StoryClusterResource` (fact sheet, source links, greenlight action).
- Studio: “Triage” panel showing top 10 scored stories with score components and a “Generate script” action.

**Acceptance criteria**

- Duplicate news items are clustered, not scripted twice.
- Scores are reproducible from stored breakdowns.
- Auto-greenlight only fires above threshold and never bypasses HITL gates.
- Dry-run performs zero paid calls; ledger shows projected $0.
- Tests: scoring formula, clustering merge, threshold bands, dry-run, idempotency.

---

### Pillar 2 — Retention-Engineered AI Script & Storyboard Automation

#### Current gap
`VideoScriptGenerator` sends one prompt and parses a single JSON response (`app/Services/AI/VideoScriptGenerator.php`). There is no hook specialization, no narrative pacing validation, no independent fact-check stage, and no per-scene directive schema.

#### Proposed architecture

**Three-stage AI chain (all through `AIManager`, with local fallback)**

1. **Hook Specialist**
   - Input: fact sheet + top entities.
   - Output: exactly 3 hooks, each ≤ 12 words, with `hook_type` (`curiosity|contrarian|stat|question`) and `retention_rationale`.
   - Stored in `script_variants` (`variant_type=hook`, `selected=false`).

2. **Narrative Architect**
   - Input: selected hook + fact sheet + target duration + language.
   - Output: scene plan with:
     - pattern interrupt every 7–10 seconds,
     - visual cue per scene,
     - narration per scene,
     - `scene_directives` JSON (see below).
   - Validation: scene durations sum to target ±5s; no scene > 12s without an interrupt.

3. **Fact-Checker & Critic**
   - Input: draft script + source fact sheet.
   - Output: `claims[]` with `supported|unsupported|uncertain`, `source_news_ids[]`, and a `critic_score` (0–100).
   - Hard rule: any `unsupported` claim blocks auto-approval; script becomes `draft` with `meta.fact_check_failed=true` and a HITL gate.

**Scene directive schema (stored in `video_scripts.scenes` JSON, backward compatible)**

```json
{
  "order": 1,
  "type": "intro",
  "duration": 8,
  "headline": "OpenAI ships...",
  "description": "...",
  "narration": "...",
  "visual_keywords": ["openai", "datacenter"],
  "directives": {
    "camera_zoom": "slow_in",
    "b_roll_keyword": "server room",
    "highlight_text": "GPT-6",
    "sfx_cue": "whoosh",
    "emotion_tone": "urgent",
    "caption_style": "kinetic_word",
    "transition": "cut"
  }
}
```

Existing consumers must treat `directives` as optional to preserve Tasks 01–10.6 behavior.

**Tables**

```text
script_variants
  id, video_script_id (fk), variant_type (hook|title|thumbnail_text),
  content (text), score (decimal 5,2), selected (bool),
  meta (json), created_at, updated_at

script_fact_checks
  id, video_script_id (fk), claim (text), status (supported|unsupported|uncertain),
  source_news_ids (json), evidence (text), critic_score, created_at
```

**Services**

- `App\Services\AI\ScriptPipeline\HookSpecialistAgent`
- `App\Services\AI\ScriptPipeline\NarrativeArchitectAgent`
- `App\Services\AI\ScriptPipeline\FactCheckerAgent`
- `App\Services\AI\ScriptPipeline\ScriptPipelineOrchestrator`
- `App\Services\AI\ScriptPipeline\SceneDirectiveValidator`

**Jobs**

- `GenerateScriptPipelineJob` (replaces direct single-prompt path behind config flag; existing `GenerateVideoScriptJob` retained as fallback).

**UI**

- Filament: `VideoScriptResource` gains “Variants”, “Fact checks”, “Directives” relation managers.
- Studio: hook selection, critic score badge, unsupported-claim warning, “Approve despite warning” requires admin role + reason.

**Acceptance criteria**

- Three-stage chain is observable as three `automation_stage_runs`.
- Unsupported claims cannot be silently approved.
- Existing single-prompt workflow remains selectable and unchanged.
- Dry-run uses Ollama/local and stores clearly labeled output.
- Tests: prompt parsing, duration validation, directive validation, fact-check blocking, fallback behavior.

---

### Pillar 3 — Word-Level Audio Sync, Dynamic Captions & Sound Design

#### Current gap
`VoiceGenerationService` records only total `duration`. `VideoScript.tsx` renders static text; there is no caption track, no word timing, no music/ducking, and no SFX.

#### Proposed architecture

**Word timings**

- Extend `voice_generations` with `word_timings` (JSON, nullable) and `timing_provider` (string, nullable) via additive migration.
- New contract `App\Contracts\WordTimingProviderInterface`:
  - `align(string $audioPath, string $transcript, string $language): array` → `[{word, start_ms, end_ms, confidence}]`.
- Implementations:
  1. `TTSWordBoundaryProvider` (uses provider metadata when available — preferred, zero extra cost).
  2. `WhisperCliProvider` (local `whisper` binary, opt-in; no network).
  3. `ForcedAlignmentFallback` (simple proportional estimate, clearly labeled `estimated=true` — never presented as verified).
- `WordTimingService` validates monotonic timings, clamps to audio duration, and stores `estimated` vs `verified`.

**Captions**

- Remotion gets a `captions` prop: `[{word, startMs, endMs}]`.
- New `KineticCaptions` component renders word-by-word highlight with configurable style (`hormozi|clean|minimal`).
- Composition remains deterministic; no random animation seeds.

**Audio post-processing (FFmpeg)**

- New `App\Services\Media\AudioMixService` builds a filter graph:
  - voice track at 0 dB,
  - music at `-18 dB` under voice (sidechaincompress or volume automation),
  - fade-in/out (configurable ms),
  - SFX placed at scene boundaries from `directives.sfx_cue`,
  - loudness normalization `loudnorm=I=-14:TP=-1.5:LRA=11` (Shorts-friendly, configurable).
- Uses `Symfony\Component\Process\Process` with argument arrays (no shell interpolation).
- Output stored under `storage/app/audio/mixed/{render_id}.m4a`; source files untouched.
- Fails closed: if FFmpeg missing, video renders without music/SFX and the export report records `audio_mix=skipped`, never `verified`.

**Tables**

```text
caption_tracks
  id, video_render_id (fk), language, source (tts|whisper|estimated),
  words (json), verified (bool), created_at, updated_at

audio_assets
  id, kind (music|sfx), name, path, license, license_url,
  duration_ms, loudness_lufs, created_at, updated_at
```

**Acceptance criteria**

- Captions align to real audio timings; estimated timings are labeled.
- Ducking reduces music by ≈18 dB while voice is active (measured in tests on a fixture).
- Render without FFmpeg still succeeds as a video-only output with truthful validation.
- No external paid alignment call in default tests.

---

### Pillar 4 — Autonomous Visual Asset & B-Roll Acquisition

#### Current gap
`VideoScript.scenes[*].visual_keywords` exist but are unused by Remotion. There is no asset library, licensing metadata, cache, or entity extraction.

#### Proposed architecture

**Pipeline**

```
Story cluster + scene directives
  → Entity extractor (local regex/NER + optional LLM)
  → Asset resolver (local cache → licensed stock → generated gradient/pattern fallback)
  → License validator
  → Asset record + Remotion prop injection
```

**Tables**

```text
visual_assets
  id, kind (logo|screenshot|stock_photo|stock_video|generated),
  source (local|pexels|unsplash|company_press_kit|generated),
  source_url, license (string), license_url, attribution,
  mime_type, width, height, duration_ms, file_hash (unique),
  path, entity_tags (json), created_at, updated_at

scene_assets
  id, video_script_id (fk), scene_order (int), visual_asset_id (fk),
  role (b_roll|logo|background|data_chart), unique(video_script_id, scene_order, role)
```

**Services**

- `EntityExtractionService` — company names, products, people, repos from fact sheet.
- `AssetHarvestService` — allowlisted domains only; reuses SSRF guard; size/type limits; `Http::timeout(20)`; no scraping of paywalled/unauthorized content.
- `AssetLicenseService` — rejects assets without verifiable license; stores attribution.
- `RemotionAssetPropsBuilder` — converts `scene_assets` into composition props:
  ```json
  {"scenes":[{"order":1,"assets":[{"type":"logo","src":"...","fit":"contain"}]}]}
  ```

**Remotion**

- `VideoScript.tsx` gains optional `assets` per scene; existing templates still render if absent.
- New `DataChartScene` and `CodeSnippetScene` components (syntax highlighting done at build time, not runtime).

**Safety**

- No automatic logo usage without license/attribution metadata.
- No downloading from arbitrary URLs from AI output; only resolved/allowlisted sources.
- Cache by SHA-256; deduplicate; cap total storage per run.

**Acceptance criteria**

- Missing license → asset rejected and fallback gradient used.
- No SSRF, no oversized files, no executable content.
- Remotion renders with and without assets (no regression).
- Tests: allowlist, license rejection, MIME sniffing, cache hit, prop mapping.

---

### Pillar 5 — Multi-Format “One-Render-Many-Outputs” Repurposing Engine

#### Current gap
`VideoRender` has one `template`, `resolution`, and `output_path`. `PlatformPresetService` validates presets but does not drive rendering. There is no 16:9 longform, no thumbnail generation, no A/B metadata variants.

#### Proposed architecture

**Tables**

```text
render_variants
  id, video_render_id (fk), aspect_ratio (9:16|16:9|1:1),
  width, height, preset, status, output_path, file_size,
  duration, verified (bool), validation_report (json),
  created_at, updated_at, unique(video_render_id, aspect_ratio)

thumbnail_variants
  id, video_render_id (fk), label (A|B|C), path, width, height,
  text_overlay, safe_area_ok (bool), created_at, updated_at

metadata_variants
  id, video_export_id (fk), variant_label, title, description,
  hashtags (json), language, platform_preset, predicted_score,
  selected (bool), created_at, updated_at
```

**Services**

- `RenderFanOutService` — dispatches one `RenderVariantJob` per configured aspect ratio with a concurrency cap (`AUTOMATION_RENDER_PARALLEL`), using `Bus::batch`.
- `RemotionCompositionResolver` — maps aspect ratio to composition props (safe-area insets, font scales).
- `ThumbnailService` — FFmpeg extracts a frame at a configurable timestamp; overlays text via Remotion/HTML render; validates dimensions and safe area.
- `MetadataVariantService` — generates 3 title/description/hashtag variants; never publishes.

**Remotion**

- `VideoScript.tsx` accepts `aspectRatio`, `safeArea`, and `captionStyle`; maintains cyber/breaking/minimal templates.
- New compositions: `VideoScript169`, `VideoScript11` (same storyboard props).

**Acceptance criteria**

- One storyboard produces N verified variants without duplicating source records.
- Invalid preset mismatch is reported, never silently re-encoded.
- Thumbnails are optional; absence does not block render.
- Tests: fan-out batch, variant idempotency, dimensions, safe area, metadata length validation.

---

### Pillar 6 — Autonomous QA, Self-Healing & Render Reliability

#### Current gap
`VideoRenderingService` marks `completed` on file existence/size; FFprobe optional; no preflight, no golden fixtures, no automatic recovery/quarantine. Task 10.6 proved the bundle path can fail silently from the user’s perspective.

#### Proposed architecture

- **Preflight service** `RenderPreflightService`:
  - bundle exists + `index.html` exists + composition discoverable,
  - Chrome executable valid,
  - FFmpeg/FFprobe executable valid (env overrides supported),
  - output directory writable,
  - disk space threshold.
- **Media verification** `MediaVerificationService`:
  - FFprobe JSON parse → codec, width, height, duration, streams,
  - expected audio only when `expect_audio=true`,
  - `verified` only when FFprobe succeeds and stream checks pass,
  - `basic_file_check` otherwise with `verified=false`.
- **Self-healing**:
  - transient failures (browser crash, timeout, OOM) → `RenderVariantJob` retry with exponential backoff,
  - permanent failures (invalid bundle, missing composition, corrupt output) → quarantine + alert, no retry storm,
  - `quarantined_renders` table or status `quarantined`,
  - automatic cleanup of temp files under `storage/app/tmp/{run_id}` only.
- **Golden render test** (opt-in group `integration`, never default):
  - renders a 3-second deterministic composition,
  - FFprobe asserts codec/resolution/duration,
  - compares file hash for repeatability (tolerance for encoder metadata).
- **Diagnostics extension**: read-only checks for bundle/index.html, Chrome, FFmpeg/FFprobe, output permissions, queue worker heartbeat, last successful render age.

**Acceptance criteria**

- No `completed` status without verified media when `require_verified=true`.
- Missing bundle/index.html fails fast with actionable error.
- Retries bounded; no duplicate renders.
- Quarantine visible in Filament and Studio.

---

### Pillar 7 — Performance Feedback & Continuous Optimization (Opt-In)

#### Current gap
No analytics ingestion, no retention/CTR feedback, no experiment assignment. Prior scope explicitly excluded analytics dashboards; this pillar is **opt-in and additive**, and must not alter existing publishing behavior.

#### Proposed architecture

- **Opt-in ingestion** (`AUTOMATION_ANALYTICS_ENABLED=false` default):
  - YouTube Analytics API adapter (read-only, separate OAuth scope `yt-analytics.readonly`),
  - manual CSV import fallback,
  - no TikTok/Facebook analytics in Phase H.
- **Tables**:
  ```text
  video_performance_snapshots
    id, youtube_publishing_state_id (fk), captured_at,
    views, impressions, ctr, avg_view_duration_seconds,
    avg_view_percentage, likes, comments, shares, source (api|csv)
  experiment_assignments
    id, experiment_key, subject_type, subject_id, variant, assigned_at
  experiment_results
    id, experiment_key, variant, metric, value, sample_size, computed_at
  ```
- **Feedback loop**:
  - performance signals update `news_scores` weights only after minimum sample size (e.g., n≥20),
  - prompt tuning suggestions are written to `prompt_optimization_suggestions` for human review — **never auto-deployed**.
- **Privacy**: aggregate metrics only; no viewer-level data; retention limits; deletion on disconnect.

**Acceptance criteria**

- Disabled by default; zero API calls when off.
- No automatic prompt changes.
- CSV import is validated and idempotent by date + video ID.
- Tests: aggregation math, sample-size guard, disconnect purge.

---

### Pillar 8 — Autonomous Orchestration & Distribution Preparation

#### Current gap
Jobs are individually dispatched; there is no end-to-end orchestrator, no run dashboard, no approval inbox, no cost/ops view. Publishing is YouTube-only and manual, which must remain the case.

#### Proposed architecture

- **Pipeline definitions** (`PipelineRegistry`):
  ```
  news_to_ready:
    score → cluster → greenlight → script_pipeline → voice
    → captions → audio_mix → render_fanout → media_verify
    → export_prepare → ready_for_review
  ```
- Each stage writes `automation_stage_runs`; the orchestrator can pause/resume/cancel.
- **HITL inbox** (`ApprovalGateResource` in Filament; Studio panel):
  - pending gates, subject context, cost estimate, approve/reject with reason,
  - public publish requires **two distinct admins** (dual control) unless explicitly overridden by config (default false).
- **Distribution preparation**:
  - reuse `PublishingService` and `YoutubePublishingProvider`,
  - no automatic upload; upload requires explicit action + private default,
  - TikTok/Facebook remain out of scope (no adapters in this proposal).
- **Ops dashboard**:
  - run success rate, queue depth, budget burn, breaker state, last verified render, stuck runs (> timeout).
- **Scheduler**:
  - `ai-pvg:automation:tick` every minute (withoutOverlapping),
  - stale-run reaper every 10 minutes,
  - budget period rollover monthly.

**Acceptance criteria**

- Every automated action is traceable to an `automation_run`.
- Paused/disabled automation leaves manual workflows fully functional.
- Public publish cannot occur without the configured gate.
- Ops dashboard is read-only for non-admins.

---

## 6. Phase G Roadmap (Core Automation)

> **Format** (strict, consistent with existing task reporting): Task ID · Phase · Priority · Status · Depends on · Objective · In scope · Out of scope · Data/migrations · Services/jobs · UI · Acceptance criteria · Test gate · Rollback · Risks.

### G-00 — Remotion Bundle & Real MP4 Baseline Repair (Prerequisite, not a feature)

- **Phase:** G (precondition)
- **Priority:** P0 — blocks all media automation
- **Status:** **Done — verified 2026-10-24**
- **Depends on:** Tasks 01–10.6
- **Objective:** Produce a genuine, FFprobe-verified MP4 by generating a Remotion-compatible bundle with `@remotion/bundler`, registering the composition, and serving it correctly.
- **In scope:** runtime bundling, `Root.tsx` composition registration, dedicated Remotion port, direct CLI render, `ai-pvg:verify-mp4`, Laravel process/FFprobe gate.
- **Out of scope:** new compositions, visual assets, captions.
- **Deliverables:** `remotion/src/Root.tsx`, `remotion/src/index.ts`, `render-cli.ts/js`, `MediaProbeService`, `RemotionRuntime`, `config/remotion.php`, tests, docs.
- **Acceptance criteria:** exit code 0; MP4 exists; FFprobe reports `h264`, 1080x1920, 30 fps, 6 s; audio-backed output includes `aac` 48 kHz with aligned timestamps.
- **Evidence:** see `PROJECT_STATUS.md` §10 and `PROJECT_ROADMAP.md` G-00.
- **Test gate:** `php artisan test` → 138 passed / 392 assertions / 0 failures / 0 risky.
- **Rollback:** keep existing static serve files; revert render CLI/service changes; output files unchanged.
- **Risks:** bundler version drift (pinned `4.0.538`); Chrome headless incompatibility; disk space.

### G-01 — Automation Control Plane

- **Phase:** G
- **Priority:** P0
- **Status:** **Done — verified 2026-10-24**
- **Depends on:** G-00 (for media stages), none for control-plane core
- **Objective:** Add run/stage ledger, budget guard, circuit breaker, dry-run, approval gates, feature flags, and diagnostics extensions.
- **In scope:** tables `automation_runs`, `automation_stage_runs`, `ai_usage_ledger`, `budget_periods`, `approval_gates`, `provider_health_events`, `feature_flags`; services listed in §4.3; `config/automation.php`; Filament `AutomationRunResource`, `ApprovalGateResource`; read-only diagnostics additions.
- **Out of scope:** any pillar-specific AI logic.
- **Acceptance criteria:** every stage writes a ledger row; budget breach opens breaker and blocks paid calls; dry-run never calls paid APIs; approvals enforce roles.
- **Test gate:** unit + feature; every G-01 test class uses `Http::preventStrayRequests()`; no external calls. Verified: 45 G-01 tests / 233 assertions; full suite 183 passed / 625 assertions.
- **Rollback:** `AUTOMATION_ENABLED=false` disables all new jobs; tables remain inert.
- **Risks:** SQLite write contention on high-frequency ledger writes; mitigate with batched writes and short transactions.

### G-02 — News Scoring, Clustering & Auto-Greenlight (Pillar 1)

- **Phase:** G
- **Priority:** P1
- **Status:** **Done — verified 2026-10-24**
- **Depends on:** G-01
- **Objective:** Rank incoming news by velocity/heat/novelty/diversity, cluster multi-source stories, auto-greenlight above threshold.
- **In scope:** `news_scores`, `story_clusters`, `story_cluster_news`; deterministic scoring/clustering/greenlight services; orchestrator stage classes; read-only Filament resources; Studio Triage panel. No autonomous G-02 job or scheduler entry was added; execution remains manual/flag-gated.
- **Out of scope:** paid trend APIs; automatic publishing; G-03 script agents; captions; assets; multi-format fan-out.
- **Deliverables:** three additive migrations; triage enums/models; `TriageGate`, `TextSimilarityService`, `NewsScoringService`, `StoryClusteringService`, `AutoGreenlightService`; `ScoreNewsStage`, `ClusterStoriesStage`, `AutoGreenlightStage`; `NewsScoreResource`, `StoryClusterResource`; Studio Triage panel; diagnostics; tests; documentation.
- **Data:** additive only (`2026_10_24_130000`–`2026_10_24_130200`). Dev DB preserved: `news=1`, `video_renders=0`, `youtube_publishing_states=0`; all three G-02 tables have `0` rows.
- **Acceptance criteria:** reproducible scores with stored breakdown; 3–5 distinct-source cluster cap; every claim/bullet grounded in valid `news_id`; auto band `>= 85`; review band `60–84`; below band inert; idempotent draft script/run or dry-run plan; idempotent review gate; flag off by default; no duplicate scripts; no network calls.
- **Evidence:**
  - Offline token/TF-IDF clustering; grounded fact sheets; ungrounded items stripped.
  - `AutomationRun` active window `cluster-{id}`; reruns return the existing script; dry-run stores `planned_script` only.
  - Review-band `ApprovalGate` type `AutoGreenlight` via `ApprovalService`; below-band no downstream action.
  - Studio action is admin-only and exposes no publishing controls.
  - G-02 suite: **22 passed / 255 assertions**; full suite: **205 passed / 883 assertions / 0 failures / 0 risky**.
- **Test gate:** every G-02 unit/feature class uses `Http::preventStrayRequests()`; no external calls in tests.
- **Rollback:** disable `AUTOMATION_TRIAGE_ENABLED` / feature flag; historical scores and clusters remain inert.
- **Risks:** clustering false merges; mitigate with the 3–5 source cap, confidence/attribution, and HITL review band.

### G-03 — Multi-Agent Script & Directives (Pillar 2)

- **Phase:** G
- **Priority:** P1
- **Depends on:** G-01, G-02
- **Objective:** Hook → Narrative → Fact-Check chain with scene directives and critic blocking.
- **In scope:** `script_variants`, `script_fact_checks`; agents; orchestrator; validator; Filament relation managers; Studio hook selection.
- **Out of scope:** image/video asset logic; captions.
- **Acceptance criteria:** 3 hooks; duration ±5s; unsupported claim blocks auto-approval; optional directives ignored by old consumers.
- **Test gate:** mocked AI responses; prompt parser fixtures; fact-check blocking.
- **Rollback:** feature flag reverts to `VideoScriptGenerator` single prompt.
- **Risks:** prompt drift; mitigate with golden fixtures and temperature caps.

### G-04 — Word Captions & Audio Design (Pillar 3)

- **Phase:** G
- **Priority:** P2
- **Depends on:** G-00, G-01, G-03
- **Objective:** Word-level timings, kinetic captions, ducking, SFX, loudness normalization.
- **In scope:** `caption_tracks`, `audio_assets`; `WordTimingService`, `AudioMixService`; Remotion captions component; FFmpeg filter-graph tests.
- **Out of scope:** paid alignment APIs by default; heavy music library.
- **Acceptance criteria:** verified vs estimated timings; ducking ≈18 dB; video-only fallback truthful.
- **Test gate:** FFmpeg fixture tests (skip if binary absent); Remotion component snapshot tests.
- **Rollback:** captions/mix flags off; render remains text-only.
- **Risks:** timing drift; mitigate with monotonic validation and duration clamps.

### G-05 — Visual Asset Harvester (Pillar 4)

- **Phase:** G
- **Priority:** P2
- **Depends on:** G-01, G-03
- **Objective:** Entity extraction, licensed asset resolution, Remotion prop injection.
- **In scope:** `visual_assets`, `scene_assets`; harvest/license/props services; allowlist config; Filament asset library; Studio asset preview.
- **Out of scope:** scraping arbitrary sites; unlicensed logos; AI image generation.
- **Acceptance criteria:** license-required; SSRF-safe; MIME/size validated; graceful fallback.
- **Test gate:** allowlist/license/MIME tests; `Http::fake`.
- **Rollback:** disable asset harvesting; templates still render text.
- **Risks:** copyright misuse; mitigate with license metadata + HITL exception gate.

### G-06 — Multi-Format Fan-Out & Metadata Variants (Pillar 5)

- **Phase:** G
- **Priority:** P2
- **Depends on:** G-00, G-01
- **Objective:** One storyboard → 9:16 + 16:9 (+1:1) variants, thumbnails, A/B metadata.
- **In scope:** `render_variants`, `thumbnail_variants`, `metadata_variants`; fan-out batch; composition resolver; thumbnail service.
- **Out of scope:** automatic publishing; platform-specific posting.
- **Acceptance criteria:** variants idempotent; preset mismatch reported; thumbnails optional.
- **Test gate:** batch/fan-out tests with fake renderer; metadata length validation.
- **Rollback:** `render.formats` reduced to `9:16`.
- **Risks:** render cost/latency; cap parallelism and require `bulk_render` gate.

### G-07 — Autonomous QA & Self-Healing (Pillar 6)

- **Phase:** G
- **Priority:** P1
- **Depends on:** G-00, G-01
- **Objective:** preflight, truthful media verification, bounded retries, quarantine, golden render.
- **In scope:** `RenderPreflightService`, `MediaVerificationService`, quarantine status/table, diagnostics extensions, golden integration test group.
- **Out of scope:** unlimited retries; destructive cleanup.
- **Acceptance criteria:** no false `completed`; actionable errors; cleanup limited to run tmp dirs.
- **Test gate:** mocked process/FFprobe fixtures; opt-in real golden render.
- **Rollback:** `require_verified=false` restores legacy behavior (explicitly documented, not default).
- **Risks:** FFprobe absence; mitigate with `verified=false` + clear status.

### G-08 — Orchestrator, HITL Inbox & Ops Dashboard (Pillar 8)

- **Phase:** G
- **Priority:** P1
- **Depends on:** G-01..G-07
- **Objective:** end-to-end pipeline orchestration, approvals, pause/resume, ops visibility.
- **In scope:** `PipelineRegistry`, orchestrator, approval inbox, ops widgets, stale-run reaper, scheduler tick.
- **Out of scope:** TikTok/Facebook adapters; automatic publishing.
- **Acceptance criteria:** every action traceable; manual workflows unaffected; public publish gated.
- **Test gate:** orchestrator state-machine tests; approval authorization tests.
- **Rollback:** `AUTOMATION_ENABLED=false`.
- **Risks:** stalled jobs; mitigate with heartbeat + reaper + alerting.

---

## 7. Phase H Roadmap (Feedback & Scale)

### H-01 — Opt-In Performance Feedback Loop (Pillar 7)
- **Priority:** P2 · **Depends on:** G-08 · **Scope:** YouTube Analytics read-only adapter + CSV import, snapshots, sample-size guards, no auto prompt changes. **Out of scope:** TikTok/Facebook analytics, viewer-level data. **Acceptance:** disabled by default; zero calls when off; disconnect purges tokens. **Test gate:** mocked analytics, aggregation math.

### H-02 — Experimentation & A/B Metadata
- **Priority:** P2 · **Depends on:** G-06, H-01 · **Scope:** deterministic variant assignment, metadata/thb variant results, selection rules. **Out of scope:** automatic winner deployment. **Acceptance:** reproducible assignments; minimum sample guard; no public changes without approval.

### H-03 — Distribution Preparation (YouTube only)
- **Priority:** P2 · **Depends on:** G-08 · **Scope:** improve existing `PublishingService` with run-linked upload state, resume/idempotency hardening, explicit dual approval for public. **Out of scope:** TikTok/Facebook/OAuth for other platforms; auto-posting. **Acceptance:** private default; uploaded ≠ public; no fabricated IDs; mocked tests only.

### H-04 — Prompt & Scoring Optimization
- **Priority:** P3 · **Depends on:** H-01, H-02 · **Scope:** suggestions queue, offline evaluation harness with golden fixtures, human approval to adopt. **Out of scope:** self-modifying production prompts. **Acceptance:** no automatic deployment; versioned prompt registry.

### H-05 — Scale, Ops & Cost Governance
- **Priority:** P3 · **Depends on:** G-08 · **Scope:** optional Redis/Horizon guidance, queue autoscaling, SLOs, cost alerts, run retention. **Out of scope:** billing/subscriptions. **Acceptance:** documented worker topology; dashboards; budget alerts.

### H-06 — Content Safety & Compliance
- **Priority:** P2 · **Depends on:** G-05, H-03 · **Scope:** copyright/license checks, claim provenance, platform policy checklist, takedown workflow. **Out of scope:** legal advice/automated DMCA. **Acceptance:** every asset has license metadata; unsupported claims blocked; audit trail.

---

## 8. Cost, Budget & Circuit-Breaker Model

```
Provider call requested
   → BudgetGuard.estimate()
   → breaker open?          → force dry-run/local fallback, record provider_health_event
   → monthly/per-run cap hit? → open breaker, emit BudgetThresholdReached, pause pipeline
   → else call provider
   → ledger.write(actual cost)
   → threshold crossed?     → alert + optional HITL gate
```

- Costs stored in **integer micros** (1 USD = 1,000,000 micros) to avoid float drift.
- Local fallback defaults to Ollama (`AIManager` already supports it) and `MockTTSProvider`.
- No paid call occurs during tests; ledger assertions use fixed fixtures.
- Breaker states: `closed → open` on quota/rate-limit/budget breach; `half_open` after cooldown with a single probe call.
- Environment examples (placeholders only):
  ```env
  AUTOMATION_ENABLED=false
  AUTOMATION_DRY_RUN=true
  AUTOMATION_MONTHLY_BUDGET_MICROS=5000000
  AUTOMATION_PER_RUN_BUDGET_MICROS=250000
  AUTOMATION_AI_PROVIDER=ollama
  AUTOMATION_TTS_PROVIDER=mock
  ```

---

## 9. HITL & Status Transition Rules

### 9.1 Automation run states

```
pending → running → completed
                 ↘ paused → running
                 ↘ failed (retryable) → running (bounded)
                 ↘ failed (permanent) → quarantined
                 ↘ cancelled
```

### 9.2 Script states (extend, don’t break existing enum)

```
draft → generating → generated → approved
                     ↘ fact_check_failed (new, optional)
                     ↘ rejected
                     ↘ failed
```

- `fact_check_failed` is additive; existing Filament labels/colors remain valid.
- `approved` still requires an authorized user (or an explicit auto-approve policy configured by an admin, default off).

### 9.3 Render/export/publish distinctions

| State | Meaning | Must never imply |
|-------|---------|------------------|
| `render.completed` | Output file exists and passed configured checks | Playable/verified media (unless `verified=true`) |
| `render.verified` | FFprobe confirmed video stream/metadata | Public availability |
| `export.completed` | Preset validation passed against verified media | Platform acceptance |
| `upload.uploaded` | Provider returned a real video ID | Public visibility |
| `publish.public` | Provider API confirms public state | — |

---

## 10. Queue Topology & Scheduling

| Queue | Workload | Workers (recommended) |
|-------|----------|-----------------------|
| `automation-high` | scoring, clustering, greenlight, script pipeline | 2 |
| `media` | voice, audio mix, render fan-out, FFprobe | 1–2 (CPU bound) |
| `publishing` | YouTube upload initiation/status | 1 |
| `default` | RSS import, maintenance | 1 |

- Scheduler entry point: `ai-pvg:automation:tick` every minute with `withoutOverlapping()`.
- Stale-run reaper: every 10 minutes.
- Budget rollover: monthly (`budget_periods`).
- SQLite caveat: keep workers low; recommend Redis + Horizon for production scale (documented, not required for dev).

---

## 11. Observability, SLOs & Diagnostics

**Events:** `RunStarted`, `StageCompleted`, `StageFailed`, `BudgetThresholdReached`, `ApprovalRequested`, `ApprovalDecided`, `RenderVerified`, `UploadCompleted`.

**Logs:** structured context `{run_id, stage, pipeline, provider, cost_micros, duration_ms}`; redact `authorization`, `code`, `access_token`, `refresh_token`, `upload_url`.

**Diagnostics additions (read-only):**
- Remotion bundle + `index.html` presence and version compatibility,
- Chrome/FFmpeg/FFprobe executable checks with configured paths,
- output directory writable + disk space,
- queue worker heartbeat (`cache` key written by a heartbeat job),
- last verified render age,
- budget breaker state and remaining budget,
- configured AI/TTS providers (booleans only, never secrets).

**SLOs (proposed):**
- 99% of scoring jobs < 60s,
- 95% of renders verified on first attempt,
- 0 paid calls in dry-run,
- 0 public uploads without dual approval,
- p95 stage failure recovery < 10 min.

---

## 12. Security, Privacy & Content Safety

- Reuse SSRF guard from `RssImportService` for all outbound asset fetches.
- Allowlist asset domains; block private IPs, redirects > 3, oversized responses.
- Validate MIME via `finfo`/`getimagesize`; reject executable content.
- Encrypt provider tokens with Laravel `Crypt` (existing pattern in `YouTubePublishingProvider`).
- Store no OAuth codes/tokens in logs; UI never displays full tokens.
- Asset licenses and attribution stored and surfaced before render/publish.
- No automatic public publishing; dual approval for public visibility.
- Fact-check provenance: unsupported claims block auto-approval.
- Data retention: automation runs 90 days by default; raw provider responses minimized.

---

## 13. Testing & Verification Strategy

| Layer | Approach | Default suite |
|-------|----------|---------------|
| Scoring/clustering | Pure unit tests with fixtures | Yes |
| Prompt chain | Mocked `AIManager` responses; parser fixtures | Yes |
| Budget/breaker | Unit tests with fixed ledger values | Yes |
| FFmpeg audio mix | Fixture-based filter-graph tests; skip if binary absent | Yes (skippable) |
| Remotion compositions | Snapshot/prop mapping tests | Yes |
| Media verification | Mocked Process/FFprobe outputs + fixture JSON | Yes |
| API providers | `Http::fake()`; no network | Yes |
| Real Remotion render | `--group=integration`, env-gated | **No** |
| Real YouTube upload | Never in automated tests | **No** |

**Rules:**
- No paid API calls, no real uploads, no browser downloads in the default suite.
- Integration tests require `AI_PVG_INTEGRATION=1` and explicit opt-in.
- Tests use isolated SQLite `:memory:`/test DB; never the dev database.
- Golden MP4 fixtures are generated locally and git-ignored; not committed.

---

## 14. Rollout, Feature Flags & Rollback

1. **G-00 first** — real verified MP4 is the gate for all media automation.
2. **G-01 control plane** — implemented dark (`AUTOMATION_ENABLED=false`) and verified 2026-10-24.
3. **G-02 news triage** — implemented dark (`AUTOMATION_TRIAGE_ENABLED=false`) with deterministic local scoring/clustering and a dry-run auto-greenlight path; verified 2026-10-24.
4. **Pillar rollouts** — each behind `feature_flags` + config; canary one pipeline, then expand.
5. **Dry-run period** — run in dry-run for ≥1 week; compare projected vs actual cost and outputs.
6. **HITL pilot** — admins only; no auto-publish.
7. **Rollback** — set flag false; jobs early-return; no data deletion; additive tables remain.

---

## 15. Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| AI hallucination / unsupported claims | Reputation/legal | Fact-check gate; source attribution; auto-approval blocked |
| Remotion bundle regression | Pipeline blocked | G-00 golden integration test; preflight checks |
| Cost runaway | Financial | Micros ledger; hard caps; breaker; local fallback |
| Copyright/asset misuse | Legal | License metadata; allowlist; HITL exception gate |
| SQLite contention | Reliability | Batched writes; low worker count; Redis/Horizon path documented |
| Duplicate renders/uploads | Cost/UX | Unique jobs + run ledger + idempotency keys |
| RSS/source poisoning | Misinformation | SSRF guard; source reputation; fact-check provenance |
| Queue backlog | Latency | Priority queues; auto-scaling guidance; stale reaper |
| Prompt drift | Quality | Golden fixtures; temperature caps; versioned prompts |
| Platform policy changes | Compliance | Config-driven presets; no hardcoded claims; review checklist |

---

## 16. KPIs & Acceptance Gates

| KPI | Target |
|-----|--------|
| Triage precision (greenlit stories approved by humans) | ≥ 80% |
| Script fact-check pass rate (first attempt) | ≥ 90% |
| Render first-attempt verification rate | ≥ 95% |
| Cost per ready-to-publish video | ≤ configured cap |
| HITL decision time | ≤ 24h median |
| Duplicate script/render/upload rate | 0 |
| Paid calls in dry-run | 0 |
| Public uploads without dual approval | 0 |

**Gate G-A:** G-00 passes real FFprobe verification. **PASSED 2026-10-24.**
**Gate G-B:** G-01..G-03 pass mocked + dry-run pilots. **G-01 and G-02 passed; G-03 pending.**
**Gate G-C:** G-04..G-08 pass opt-in integration tests.
**Gate H-A:** H-01..H-03 pass privacy/authorization review.

---

## 17. Open Questions / Decisions Needed

1. Which paid AI/TTS providers are approved for Phase G pilots, and what monthly cap?
2. Is Redis/Horizon approved for production queues, or must SQLite/database queues remain the only target?
3. Which stock asset sources are licensed for use (Pexels/Unsplash/company press kits)?
4. Who are the two approvers for public publishing?
5. Should auto-greenlight create scripts only, or also auto-generate voice (still without render/publish)?
6. What is the retention period for automation runs and provider responses?
7. Which languages are in scope for captions in Phase G (ar/en/fr)?
8. Is Whisper installed/allowed for word alignment, or should TTS metadata be the only Phase G source?

---

## 18. Appendix A — Proposed File Map (No Files Created by This Proposal)

### New directories

```
app/Services/Automation/
  AutomationOrchestrator.php
  BudgetGuard.php
  DryRunContext.php
  ApprovalService.php
  CircuitBreaker.php
  PipelineRegistry.php
  StageResult.php
  Scoring/NewsScoringService.php
  Clustering/StoryClusteringService.php
  Greenlight/AutoGreenlightService.php
app/Services/AI/ScriptPipeline/
  HookSpecialistAgent.php
  NarrativeArchitectAgent.php
  FactCheckerAgent.php
  ScriptPipelineOrchestrator.php
  SceneDirectiveValidator.php
app/Services/Media/
  WordTimingService.php
  AudioMixService.php
  MediaVerificationService.php
  RenderPreflightService.php
  RenderFanOutService.php
  AssetHarvestService.php
  AssetLicenseService.php
  RemotionAssetPropsBuilder.php
  ThumbnailService.php
app/Jobs/Automation/
  ScoreNewsJob.php
  ClusterStoriesJob.php
  AutoGreenlightJob.php
  GenerateScriptPipelineJob.php
  RenderVariantJob.php
  ReapStaleRunsJob.php
app/Console/Commands/
  AutomationTickCommand.php
app/Filament/Resources/
  AutomationRuns/
  StoryClusters/
  ApprovalGates/
  VisualAssets/
  RenderVariants/
config/automation.php
```

### Additive migrations (proposed names only)

```
2026_11_01_000100_create_automation_runs_table.php
2026_11_01_000200_create_automation_stage_runs_table.php
2026_11_01_000300_create_ai_usage_ledger_table.php
2026_11_01_000400_create_budget_periods_table.php
2026_11_01_000500_create_approval_gates_table.php
2026_11_01_000600_create_provider_health_events_table.php
2026_11_01_000700_create_feature_flags_table.php
2026_11_01_000800_create_news_scores_table.php
2026_11_01_000900_create_story_clusters_table.php
2026_11_01_001000_create_story_cluster_news_table.php
2026_11_01_001100_create_script_variants_table.php
2026_11_01_001200_create_script_fact_checks_table.php
2026_11_01_001300_create_caption_tracks_table.php
2026_11_01_001400_create_audio_assets_table.php
2026_11_01_001500_create_visual_assets_table.php
2026_11_01_001600_create_scene_assets_table.php
2026_11_01_001700_create_render_variants_table.php
2026_11_01_001800_create_thumbnail_variants_table.php
2026_11_01_001900_create_metadata_variants_table.php
```

All migrations are **additive**; no column drops, no destructive operations, no reseeding.

### Files expected to change (not changed by this proposal)

- `routes/console.php` — additional scheduled jobs.
- `config/services.php` — provider toggles (placeholders only).
- `.env.example` — placeholder variables.
- `app/Models/VideoScript.php` — optional new relations (`variants`, `factChecks`), no breaking casts.
- `app/Models/VideoRender.php` — optional `variants()` relation.
- `app/Jobs/GenerateVideoScriptJob.php` — branch to pipeline behind flag; legacy path preserved.
- `app/Services/VideoRenderingService.php` — preflight + verified-media checks; legacy fallback behind config.
- `app/Services/VideoExportService.php` — require verified media for publishing gate.
- `remotion/src/VideoScript.tsx` — optional `assets`, `captions`, `aspectRatio`, `directives` props (backward compatible).
- `app/Livewire/StudioDashboard.php` — pipeline stage/status panels; eager loading to avoid N+1.
- Filament resources — relation managers and read-only score/approval views.

---

## 19. Appendix B — Glossary

- **HITL:** Human-in-the-loop approval gate.
- **Dry-run:** Execute the pipeline logic without paid provider calls or external publishing; outputs are labeled.
- **Breaker:** Circuit breaker that pauses paid calls after quota/rate/budget failures.
- **Micros:** Integer USD × 1,000,000 for exact cost accounting.
- **Fact sheet:** Source-attributed claims extracted from clustered news.
- **Verified media:** FFprobe-confirmed video stream/metadata; not merely an existing file.
- **Uploaded vs public:** Provider accepted the upload vs provider confirms public visibility.

---

**End of proposal.** G-00, G-01, and G-02 are approved, implemented, and verified. Implementation of G-03 and later phases requires explicit approval.
