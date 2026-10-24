# AI-PVG — Project Roadmap

**Status:** Active roadmap — G-00, G-01, G-02, G-03 complete; G-04 pre-flight next
**Baseline:** Tasks 01–10.6 · G-00 verified · G-01 verified · G-02 verified · G-03 verified 2026-10-24
**Current suite:** 241 tests / 1176 assertions / 0 failures / 0 risky
**Companion architecture:** `AUTOMATION_EXPANSION_PROPOSAL.md`
**Safety:** Every task is additive, config-gated, dry-run capable, budget-bounded, and HITL-aware. No automatic public publishing. No destructive migrations.

> **Format:** Each task records Phase · Priority · Status · Depends on · Objective · In scope · Out of scope · Deliverables · Data · Acceptance criteria · Test gate · Rollback · Risks. Status values: Proposed / Approved / In Progress / Blocked / Done. A task is **Done** only when its acceptance criteria and test gate pass with real evidence.

---

## Phase G — Core Automation

### G-00 — Remotion Bundle & Verified MP4 Baseline
- **Phase:** G (prerequisite)
- **Priority:** P0
- **Status:** **Done — verified 2026-10-24**
- **Depends on:** Tasks 01–10.6
- **Objective:** Generate a Remotion-compatible bundle, register compositions, and produce a genuine MP4 verified by FFprobe.
- **In scope:** `@remotion/bundler` bundle step; composition registration; dedicated Remotion port; direct CLI render; `ai-pvg:verify-mp4` real output; Laravel `Symfony Process` invocation and FFprobe gate.
- **Out of scope:** new compositions, captions, assets, publishing.
- **Deliverables:** `remotion/src/Root.tsx`, `remotion/src/index.ts`, rewritten `remotion/src/render-cli.ts`, hardened `VideoRenderingService`, `MediaProbeService`, `RemotionRuntime`, `config/remotion.php`, tests, docs.
- **Data:** no migrations; dev DB untouched (`news=1`, renders=0, youtube_states=0).
- **Acceptance criteria:** exit 0; MP4 exists and is non-empty; FFprobe returns `h264`, 1080x1920, 30 fps, 6 s; audio-backed render returns `aac` 48 kHz and aligned timestamps; verify command reports real metadata.
- **Evidence:**
  - `storage/app/video_out/g00_laravel_verified.mp4` — 35,384 B, `h264`, 1080x1920, 30 fps, 6.000 s, 180 frames, SHA-256 `4d9babd096e810281d6dd42b265ab647d140bdb7526c257aefa61f446810dd70`.
  - `storage/app/video_out/g00_audio_laravel_verified.mp4` — 283,797 B, `h264` + `aac` stereo 48 kHz, video 6.000 s / audio 6.016 s, SHA-256 `e5bd33f4fe06237bfec33483b345737b5d70a20b50130dd70ea8ed8742b4f646`.
  - Port-isolation regression: render succeeded while a stale server occupied port 3000 (`g00_port_guard_verified.mp4`).
- **Test gate:** `php artisan test` → 138 passed / 392 assertions / 0 failures / 0 risky.
- **Rollback:** keep the prior `serve`/`static-serve` files in place; revert `render-cli` and service changes if needed; output files unchanged.
- **Risks:** bundler version drift (pinned `4.0.538`); Chrome compatibility; disk space.

### G-01 — Automation Control Plane
- **Phase:** G
- **Priority:** P0
- **Status:** **Done — verified 2026-10-24**
- **Depends on:** G-00 for media stages; none for core
- **Objective:** Shared run ledger, stage ledger, budget guard, circuit breaker, dry-run, approval gates, feature flags, diagnostics.
- **In scope:** tables `automation_runs`, `automation_stage_runs`, `ai_usage_ledger`, `budget_periods`, `approval_gates`, `provider_health_events`, `feature_flags`; services/contracts; `config/automation.php`; Filament resources; read-only diagnostics.
- **Out of scope:** pillar-specific AI logic.
- **Deliverables:** seven additive migrations, automation enums/models/events/exceptions, `BudgetGuard`, `CircuitBreaker`, `DryRunContext`, `ApprovalService`, `FeatureFlagService`, `PipelineRegistry`, `AutomationOrchestrator`, idempotent tick command, scheduler entry, read-only Filament resources, diagnostics extensions, tests.
- **Data:** additive only. Migration range `2026_10_24_120000`–`2026_10_24_120600`. Dev data preserved: `news=1`, `video_renders=0`, `youtube_publishing_states=0`; all seven control-plane tables start empty.
- **Acceptance criteria:** every stage is traceable; budget breach blocks paid calls; dry-run never calls paid providers; approvals enforce roles.
- **Evidence:**
  - `BudgetGuard::authorize()` performs the monthly and per-run reservations inside `DB::transaction()` using conditional `UPDATE`s and affected-row checks; a monthly breach opens the budget breaker and subsequent paid calls fail closed.
  - `PublicPublish` dual approval records distinct first/second admin IDs and timestamps; rejects re-use of the same admin and the configured system user for human-initiated requests.
  - `ai-pvg:automation:tick` returns success immediately when `automation.enabled` is false and does not touch control-plane tables.
  - `AutomationRunResource` and `ApprovalGateResource` expose list/view only; `canCreate`, `canEdit`, `canDelete`, and `canDeleteAny` return false; missing tables fail closed.
  - Read-only diagnostics report automation, budget, approval, provider-health, and feature-flag state without creating a budget period or mutating data.
  - G-01 suite: **45 passed / 233 assertions**; full suite: **183 passed / 625 assertions / 0 failures / 0 risky**.
- **Test gate:** every G-01 unit/feature class calls `Http::preventStrayRequests()`; zero external network calls; no real provider, upload, or publishing action.
- **Rollback:** `AUTOMATION_ENABLED=false` disables all new jobs and leaves the additive tables inert.
- **Risks:** SQLite write contention; mitigate with short transactions, batching, and low worker counts.

### G-02 — News Scoring, Clustering & Auto-Greenlight
- **Phase:** G
- **Priority:** P1
- **Status:** **Done — verified 2026-10-24**
- **Depends on:** G-01
- **Objective:** Rank news by velocity/heat/novelty/diversity, cluster multi-source stories, auto-greenlight above threshold.
- **In scope:** `news_scores`, `story_clusters`, `story_cluster_news`; deterministic scoring/clustering/greenlight services; orchestrator stage classes; read-only Filament resources; Studio Triage panel. No autonomous G-02 job or scheduler entry was added; execution remains manual/flag-gated.
- **Out of scope:** paid trend APIs; automatic publishing; G-03 script agents; captions; asset harvesting; multi-format fan-out.
- **Deliverables:** three additive migrations; `TriageBand`, `StoryClusterStatus`, `TriageDisabledException`; `NewsScore`, `StoryCluster`, `StoryClusterNews`; `TriageGate`, `TextSimilarityService`, `NewsScoringService`, `StoryClusteringService`, `AutoGreenlightService`, `GreenlightResult`; `ScoreNewsStage`, `ClusterStoriesStage`, `AutoGreenlightStage`; read-only `NewsScoreResource` / `StoryClusterResource`; Studio Triage panel; diagnostics; tests; docs.
- **Data:** additive only. Migration range `2026_10_24_130000`–`2026_10_24_130200`. Dev data preserved: `news=1`, `video_renders=0`, `youtube_publishing_states=0`; `news_scores=0`, `story_clusters=0`, `story_cluster_news=0`.
- **Acceptance criteria:** reproducible score breakdown; 3–5 distinct-source cluster cap; every claim/bullet grounded in member `news_id`; auto-greenlight band `>= 85`, review band `60–84`, below band inert; one idempotent draft script or dry-run plan; idempotent review gate; feature flag off by default; no duplicate scripts; no network calls.
- **Evidence:**
  - Scoring stores all five component scores, weighted contributions, thresholds, evidence, explanations, and composite/band in `news_scores.breakdown`.
  - Clustering defaults to offline token/TF-IDF cosine similarity with zero HTTP or paid calls; persisted clusters are limited to 3–5 distinct sources; ungrounded fact-sheet items are stripped.
  - Auto-greenlight uses pipeline `triage.auto_greenlight`, subject `StoryCluster`, active window `cluster-{id}`; reruns return the same run/script. Dry-run stores `planned_script` in `AutomationRun.meta` and creates no `VideoScript`.
  - Review-band clusters open an idempotent `ApprovalGate` of type `AutoGreenlight` through `ApprovalService`; below-band clusters create no downstream records.
  - Studio action is admin-only, fails closed when triage/tables are unavailable, and exposes no publishing controls.
  - G-02 suite: **22 passed / 255 assertions**; full suite: **205 passed / 883 assertions / 0 failures / 0 risky**.
- **Test gate:** every G-02 unit/feature class calls `Http::preventStrayRequests()`; tests assert no requests were sent; no real provider, upload, or publishing action.
- **Rollback:** set `AUTOMATION_TRIAGE_ENABLED=false` / flag false; historical scores and clusters remain inert.
- **Risks:** false merges; mitigate with the 3–5 source cap, confidence/attribution, and HITL review band.

### G-03 — Multi-Agent Script & Scene Directives
- **Phase:** G
- **Priority:** P1
- **Status:** **Done — verified 2026-10-24**
- **Depends on:** G-01, G-02
- **Objective:** Hook Specialist → Narrative Architect → Fact-Checker chain; inject scene directives; block unsupported claims.
- **In scope:** `script_variants`, `script_fact_checks`; deterministic hook/narrative/fact-check agents; orchestrator stages; strict directive validator; read-only Filament resources; Studio hook selection, directive preview/diff, and fact-check review. No autonomous G-03 job or scheduler entry was added; execution remains manual/flag-gated.
- **Out of scope:** assets, captions, publishing.
- **Deliverables:** two additive migrations; `ScriptVariantType`, `ScriptHookType`, `ScriptFactCheckVerdict`, `ApprovalGateType::ScriptFactCheck`; `ScriptVariant`, `ScriptFactCheck`; `ScriptSource`, `ScriptSourceFactory`, `ScriptAgentsGate`, `ScriptAgentAiClient`, `DirectiveValidator`, `HookSpecialistAgent`, `NarrativeArchitectAgent`, `FactCheckerAgent`, `ScriptPipelineService`; `GenerateScriptVariantsStage`, `ComposeScriptStage`, `FactCheckScriptStage`; read-only `ScriptVariantResource` / `ScriptFactCheckResource`; Studio review panel; diagnostics; Remotion optional directive fields; tests; docs.
- **Data:** additive only. Migration range `2026_10_24_140000`–`2026_10_24_140100`. Dev data preserved: `news=1`, `video_renders=0`, `youtube_publishing_states=0`; `script_variants=0`, `script_fact_checks=0`.
- **Acceptance criteria:** exactly three distinct grounded hooks with deterministic `hook_index` `1..3` and explicit `source_news_ids`; strict scene directive schema with total duration within ±5s of target; unsupported/uncertain claims block auto-approval and open a review gate; approved scripts are never overwritten; legacy scenes without directives render unchanged; dual gate off by default; dry-run performs zero paid calls and zero production script mutations; idempotent reruns create no duplicates; no network calls.
- **Evidence:**
  - Exactly three distinct hooks are generated and persisted per run; duplicates or a short set throw before persistence.
  - Active window `cluster-{id}:g03-v1` or `news-{id}:g03-v1`; reruns return the existing run/script/variants.
  - `DirectiveValidator` enforces the directive key set, contiguous scene order, source IDs, and total duration tolerance; legacy scenes pass through unchanged.
  - Every claim is persisted with `supported|unsupported|uncertain`, `source_news_ids`, and evidence; blocking verdicts keep the script in `Draft` and open an `ApprovalGate` of type `ScriptFactCheck`.
  - Optional external AI is inactive by default; when explicitly enabled it passes `BudgetGuard::authorize()`, `DryRunContext`, and writes an `ai_usage_ledger` row with purpose `Script` or `Critic`.
  - Dry-run returns labeled `planned_variants` / `planned_scenes` / `planned_fact_checks` and mutates no production `VideoScript`.
  - Studio is admin-only for hook selection, refuses approved scripts, and exposes no publishing controls; resources fail closed when tables are missing.
  - G-03 suite: **36 passed / 293 assertions**; full suite: **241 passed / 1176 assertions / 0 failures / 0 risky**.
- **Test gate:** every G-03 unit/feature class calls `Http::preventStrayRequests()` or an explicit `Http::fake()`; default-mode tests assert zero requests; no real provider, upload, or publishing action.
- **Rollback:** disable `automation.script_agents.enabled` and the `script_agents.enabled` flag; the existing `VideoScriptGenerator` single-prompt path remains the fallback and the additive tables stay inert.
- **Risks:** prompt drift and hallucinated claims; mitigate with deterministic local agents, golden fixtures, strict source grounding, fact-check gating, budget caps, and HITL review.

### G-04 — Word Captions & Audio Design
- **Phase:** G
- **Priority:** P2
- **Status:** Proposed
- **Depends on:** G-00, G-01, G-03
- **Objective:** Word-level timings, kinetic captions, music ducking, SFX, loudness normalization.
- **In scope:** `caption_tracks`, `audio_assets`; `WordTimingService`, `AudioMixService`; Remotion captions component; FFmpeg filter-graph tests.
- **Out of scope:** paid alignment APIs by default; large music library.
- **Deliverables:** migrations, services, Remotion component, tests, docs.
- **Data:** additive; `voice_generations.word_timings` nullable.
- **Acceptance criteria:** estimated vs verified timings labeled; ducking ≈18 dB; video-only fallback truthful.
- **Test gate:** FFmpeg fixture tests (skip if absent); Remotion snapshot tests.
- **Rollback:** captions/mix flags off.
- **Risks:** timing drift; monotonic validation + clamps.

### G-05 — Visual Asset Harvester
- **Phase:** G
- **Priority:** P2
- **Status:** Proposed
- **Depends on:** G-01, G-03
- **Objective:** Entity extraction, licensed asset resolution, Remotion prop injection.
- **In scope:** `visual_assets`, `scene_assets`; harvest/license/props services; allowlist config; asset library UI.
- **Out of scope:** scraping arbitrary sites; AI image generation; unlicensed logos.
- **Deliverables:** migrations, services, Remotion props, tests.
- **Data:** additive.
- **Acceptance criteria:** license required; SSRF-safe; MIME/size validated; graceful fallback.
- **Test gate:** allowlist/license/MIME tests with `Http::fake`.
- **Rollback:** disable harvesting; text-only templates.
- **Risks:** copyright misuse; HITL exception gate.

### G-06 — Multi-Format Fan-Out & Metadata Variants
- **Phase:** G
- **Priority:** P2
- **Status:** Proposed
- **Depends on:** G-00, G-01
- **Objective:** One storyboard → 9:16 + 16:9 (+1:1), thumbnails, A/B metadata.
- **In scope:** `render_variants`, `thumbnail_variants`, `metadata_variants`; batch fan-out; composition resolver; thumbnail service.
- **Out of scope:** automatic publishing.
- **Deliverables:** migrations, services, jobs, Remotion compositions, UI, tests.
- **Data:** additive.
- **Acceptance criteria:** variants idempotent; preset mismatch reported; thumbnails optional.
- **Test gate:** fake renderer batch tests; metadata length validation.
- **Rollback:** config reduces formats to `9:16`.
- **Risks:** render cost; cap parallelism + `bulk_render` gate.

### G-07 — Autonomous QA & Self-Healing
- **Phase:** G
- **Priority:** P1
- **Status:** Proposed
- **Depends on:** G-00, G-01
- **Objective:** Preflight, truthful media verification, bounded retries, quarantine, golden render.
- **In scope:** `RenderPreflightService`, `MediaVerificationService`, quarantine, diagnostics, opt-in golden render.
- **Out of scope:** unlimited retries; destructive cleanup.
- **Deliverables:** services, status/table changes (additive), tests, docs.
- **Data:** additive.
- **Acceptance criteria:** no false `completed`; actionable errors; cleanup limited to run temp dirs.
- **Test gate:** mocked process/FFprobe; opt-in real golden render.
- **Rollback:** `require_verified=false` legacy mode (documented, not default).
- **Risks:** FFprobe absence; `verified=false` + clear status.

### G-08 — Orchestrator, HITL Inbox & Ops Dashboard
- **Phase:** G
- **Priority:** P1
- **Status:** Proposed
- **Depends on:** G-01..G-07
- **Objective:** End-to-end orchestration, approvals, pause/resume, ops visibility.
- **In scope:** `PipelineRegistry`, orchestrator, approval inbox, ops widgets, stale-run reaper, scheduler tick.
- **Out of scope:** TikTok/Facebook adapters; automatic publishing.
- **Deliverables:** services, Filament/Livewire UI, scheduler, tests, runbooks.
- **Data:** additive.
- **Acceptance criteria:** every action traceable; manual workflows intact; public publish gated.
- **Test gate:** orchestrator state-machine tests; authorization tests.
- **Rollback:** `AUTOMATION_ENABLED=false`.
- **Risks:** stalled jobs; heartbeat + reaper + alerts.

---

## Phase H — Feedback, Scale & Compliance

### H-01 — Opt-In Performance Feedback Loop
- **Phase:** H · **Priority:** P2 · **Status:** Proposed · **Depends on:** G-08
- **Objective:** Read-only YouTube Analytics/CSV ingestion feeding aggregate retention signals.
- **In scope:** snapshots, sample-size guards, CSV import, disconnect purge.
- **Out of scope:** TikTok/Facebook analytics; viewer-level data; auto prompt changes.
- **Acceptance criteria:** disabled by default; zero calls when off; privacy-safe aggregates.

### H-02 — Experimentation & A/B Metadata
- **Phase:** H · **Priority:** P2 · **Status:** Proposed · **Depends on:** G-06, H-01
- **Objective:** Deterministic variant assignment and results; human-approved winner application.
- **In scope:** assignments, results, minimum sample rules.
- **Out of scope:** automatic winner deployment.

### H-03 — Distribution Preparation (YouTube Only)
- **Phase:** H · **Priority:** P2 · **Status:** Proposed · **Depends on:** G-08
- **Objective:** Harden existing `PublishingService` run linkage, idempotency, resume, dual approval.
- **In scope:** YouTube only; private default; uploaded≠public.
- **Out of scope:** TikTok/Facebook/OAuth; auto-posting.

### H-04 — Prompt & Scoring Optimization
- **Phase:** H · **Priority:** P3 · **Status:** Proposed · **Depends on:** H-01, H-02
- **Objective:** Suggestion queue + offline evaluation harness; human adoption only.
- **Out of scope:** self-modifying production prompts.

### H-05 — Scale, Ops & Cost Governance
- **Phase:** H · **Priority:** P3 · **Status:** Proposed · **Depends on:** G-08
- **Objective:** Optional Redis/Horizon guidance, SLOs, alerts, retention.
- **Out of scope:** billing/subscriptions.

### H-06 — Content Safety & Compliance
- **Phase:** H · **Priority:** P2 · **Status:** Proposed · **Depends on:** G-05, H-03
- **Objective:** License/attribution enforcement, claim provenance, policy checklist, takedown workflow.
- **Out of scope:** legal advice; automated DMCA.

---

## Gates

| Gate | Requirement | Status |
|------|-------------|--------|
| G-A | G-00 produces a genuine FFprobe-verified MP4 | **Passed** (video + synthetic audio; 2026-10-24) |
| G-B | G-01..G-03 pass mocked tests + dry-run pilot | **Passed** — G-01, G-02, and G-03 verified 2026-10-24 |
| G-C | G-04..G-08 pass opt-in integration tests | Proposed |
| H-A | H-01..H-03 pass privacy/authorization review | Proposed |

## Cross-Cutting Rules

1. Additive migrations only; no resets, truncation, or destructive seeding.
2. Dry-run and local fallback (Ollama/mock TTS) are defaults for new automation.
3. No paid calls or real uploads in automated tests.
4. Public publishing requires explicit, dual-admin approval.
5. `verified=false` never becomes verified without real media verification.
6. All statuses, costs, and approvals are recorded in the automation ledger.
7. Existing Tasks 01–10.6 behavior and manual workflows remain intact.

**End of roadmap.**
