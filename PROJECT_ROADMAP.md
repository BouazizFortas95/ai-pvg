# AI-PVG — Project Roadmap

**Status:** **DONE — MVP Production-Ready Blueprint Complete**

**Delivered scope:** G-00 through G-06 plus Phase H

**Baseline:** Tasks 01–10.6 · G-00..G-06 verified · Phase H verified 2026-10-25

**Current suite:** 258 tests / 1265 assertions / 0 failures / 0 risky

**Companion documents:** `PROJECT_STATUS.md` · `AUTOMATION_EXPANSION_PROPOSAL.md`

**Safety:** Additive migrations only; config-gated and dry-run by default; no automatic public publishing.

> **Closure note:** The authorized hyper-automation MVP roadmap is complete. Original post-MVP concepts G-07, G-08, and the remaining H-items (H-01, H-02, H-04..H-06) were not authorized for this delivery and remain optional future work; no such feature was started. See “Post-MVP Backlog” below.

---

## Phase G — Core Automation

### G-00 — Remotion Bundle & Verified MP4 Baseline
- **Phase:** G (prerequisite)
- **Priority:** P0
- **Status:** **Done — verified 2026-10-24**
- **Objective:** Produce genuine FFprobe-verified MP4s from Remotion with a dedicated runtime and Laravel integration.
- **Deliverables:** `@remotion/bundler` bundle, composition registration, dedicated port, render CLI, `MediaProbeService`, `RemotionRuntime`, `config/remotion.php`, tests, docs.
- **Evidence:** Two real MP4s verified (`h264`, 1080x1920, 30 fps, 6 s; audio variant includes `aac` stereo 48 kHz). Port-isolation regression passed.
- **Data:** no migrations; dev DB preserved.
- **Rollback:** retain prior static-serve path; revert render CLI/service changes.
- **Risks:** bundler version drift, Chrome compatibility, disk space.

### G-01 — Automation Control Plane
- **Phase:** G
- **Priority:** P0
- **Status:** **Done — verified 2026-10-24**
- **Objective:** Run/stage ledger, budget guard, circuit breaker, dry-run, approval gates, feature flags, and read-only diagnostics.
- **Data:** additive `automation_runs`, `automation_stage_runs`, `ai_usage_ledger`, `budget_periods`, `approval_gates`, `provider_health_events`, `feature_flags`.
- **Acceptance:** every stage traceable; budget breach blocks paid calls; dry-run performs zero paid calls; approvals enforce admin roles; public-publish gates support two distinct admins.
- **Rollback:** `AUTOMATION_ENABLED=false`.
- **Risks:** SQLite write contention; use short transactions and low worker counts.

### G-02 — News Scoring, Clustering & Auto-Greenlight
- **Phase:** G
- **Priority:** P1
- **Status:** **Done — verified 2026-10-24**
- **Objective:** Deterministic offline scoring, 3–5 source clustering, and thresholded greenlight.
- **Data:** additive `news_scores`, `story_clusters`, `story_cluster_news`.
- **Acceptance:** reproducible score breakdown; grounded fact sheets; auto band `>= 85`; review band `60–84`; below band inert; idempotent draft creation; no network calls.
- **Rollback:** disable `AUTOMATION_TRIAGE_ENABLED` / triage flag.

### G-03 — Multi-Agent Script & Scene Directives
- **Phase:** G
- **Priority:** P1
- **Status:** **Done — verified 2026-10-24**
- **Objective:** Hook → narrative → fact-check chain with strict scene directives.
- **Data:** additive `script_variants`, `script_fact_checks`.
- **Acceptance:** exactly three grounded hooks; ±5s duration validation; unsupported claims block auto-approval; approved scripts protected; legacy scenes unchanged; dry-run zero paid calls; idempotent reruns.
- **Rollback:** disable script-agents config + flag; legacy single-prompt path remains.

### G-04 — Word Captions & Audio Design
- **Phase:** G
- **Priority:** P2
- **Status:** **Done — verified 2026-10-24**
- **Objective:** Word-level timing plans, kinetic captions, music ducking, SFX placement, and loudness normalization.
- **Data:** additive `caption_tracks`, `audio_assets`; nullable timing support on voice generation.
- **Deliverables:** `WordTimingService`/caption builder, `AudioMixService`, Remotion captions, filter-graph planning, read-only Filament views, Studio preview, diagnostics, tests.
- **Acceptance:** estimated vs verified timing labels; monotonic timings within audio duration; ducking target configured; truthful video-only fallback; legacy renders unchanged.
- **Rollback:** disable `automation.captions.enabled` and the `captions.enabled` flag.
- **Risks:** timing drift and filter-graph regressions; mitigated with clamps and fixture tests.

### G-05 — Visual Asset Harvester
- **Phase:** G
- **Priority:** P2
- **Status:** **Done — verified 2026-10-24**
- **Objective:** Entity extraction, allowlisted asset resolution, license metadata, and Remotion prop injection.
- **Data:** additive `visual_assets`, `scene_assets`.
- **Deliverables:** `VisualAssetHarvester`, `ScenePropBuilder`, `RemotionPropInjector`, read-only resources, Studio preview, diagnostics, tests.
- **Acceptance:** allowlist + SSRF-safe fetching; MIME/size validation; license metadata required; graceful text-only fallback; no arbitrary scraping.
- **Rollback:** disable `automation.assets.enabled` / `assets.enabled`.

### G-06 — Multi-Format Fan-Out & Metadata Variants
- **Phase:** G
- **Priority:** P2
- **Status:** **Done — verified 2026-10-24**
- **Objective:** One storyboard → 9:16, 16:9, and 1:1 variants; midpoint thumbnails; A/B metadata planning.
- **Data:** additive `render_variants`, `thumbnail_variants`, `metadata_variants`.
- **Deliverables:** `PresetFormat`, `PresetValidator`, `FanOutBatchService`, `ThumbnailBuilder`, Remotion aspect payloads, read-only Filament resources, Studio preview, diagnostics, tests.
- **Acceptance:** strict preset/resolution pairs; deterministic render keys; midpoint thumbnail plan; idempotent variants; dry-run plans with zero render execution/disk writes; legacy single-format behavior unchanged.
- **Rollback:** disable `automation.formats.enabled` / `formats.enabled` and reduce presets to 9:16.
- **Risks:** render cost; parallelism remains capped and dry-run by default.

---

## Phase H — Publishing Integration, Co-Pilot & Autopilot Gates

**Status:** **DONE — verified 2026-10-25**

**Objective:** Add a fail-closed public-publish guard, admin-only Co-Pilot review audit, and a global autopilot preflight without ever automating a public upload.

### H-D1 — Additive approval and review data
- `publishing_approval_sessions`: script link, status, first/second approver IDs and timestamps, rejection reason.
- `co_pilot_review_sessions`: reviewer, target type/id, action, note, status.
- Additive migrations only; no destructive changes to `youtube_publishing_states`.

### H-D2 — Dual-admin public-publish guard
- `PublishingGate` requires config + database flag and defaults closed.
- `YouTubePublishService` requires an approved session and/or approved `PublicPublish` gate, with two distinct existing admin users.
- Missing tables, disabled gate, pending/rejected approval, same-admin approval, non-admin approvers, or absent approval all fail closed.
- The service performs no upload and no network call.

### H-D3 — Co-Pilot review inbox
- `CoPilotReviewService` is admin-only and append-only.
- Supports script/render/asset targets and approve/reject actions.
- Never publishes, renders, uploads, or mutates the reviewed subject.

### H-D4 — Autopilot preflight
- `AutopilotGateService` defaults to blocked.
- Requires G-01 master switch, G-02..G-06 feature gates, remaining budget, and a closed budget circuit breaker.
- Returns explicit block reasons for diagnostics and UI.

### H-D5 — Read-only UI
- `PublishingStateResource` and `CoPilotSessionResource` are read-only (no create/edit/delete) and fail closed when tables are missing.
- Studio shows gate state, approval counts, distinct-admin approvals, Co-Pilot review counts, and recent audit rows. No publish/upload triggers.

### H-D6 — Tests and safety
- Phase H suite: **12 passed / 53 assertions**.
- Every Phase H test calls `Http::preventStrayRequests()`.
- Positive and negative coverage includes disabled gate, same-admin approval, non-admin approvers, approved gate path, autopilot all-flags behavior, open breaker, admin-only Co-Pilot, read-only resources, Studio oversight, and missing-table fail-closed behavior.

**Rollback:** set `AUTOMATION_PUBLISHING_ENABLED=false` and `publishing.enabled=false`; existing audit rows remain inert and readable.

---

## Gates

| Gate | Requirement | Status |
|---|---|---|
| G-A | G-00 produces genuine FFprobe-verified MP4 | **Passed** 2026-10-24 |
| G-B | G-01..G-03 pass mocked tests + dry-run pilots | **Passed** 2026-10-24 |
| G-C | G-04..G-06 pass opt-in tests with additive schema and truthful fallbacks | **Passed** 2026-10-24 |
| G-D | Phase H dual-admin, Co-Pilot, autopilot, UI, diagnostics, and no-upload proofs pass | **Passed** 2026-10-25 |
| H-A | Phase H privacy/authorization review: admin-only Co-Pilot, fail-closed tables, no automated public publish | **Passed** 2026-10-25 |

---

## Post-MVP Backlog (Deferred, Not Authorized)

These items appeared in earlier planning drafts but were not part of the authorized Phase H delivery. They remain optional and unimplemented:

- **G-07** — broader autonomous QA/self-healing and quarantine automation.
- **G-08** — additional HITL/ops dashboard automation beyond the existing control plane and Phase H oversight.
- **H-01** — opt-in YouTube Analytics/CSV performance feedback.
- **H-02** — automated experiment winner deployment (human approval remains mandatory if ever built).
- **H-04** — prompt/scoring optimization adoption pipeline.
- **H-05** — Redis/Horizon scaling, SLO automation, and cost-governance expansion.
- **H-06** — compliance/takedown workflow automation.

No post-MVP item was started.

---

## Cross-Cutting Rules

1. Additive migrations only; no resets, truncation, or destructive seeding.
2. Dry-run/local fallback remains the default for new automation.
3. No paid calls, live uploads, or public publishing in automated tests.
4. Public publishing requires two distinct administrators and explicit human authorization.
5. `verified=false` never becomes verified without real media verification.
6. All statuses, costs, and approvals are recorded in the automation ledger/audit tables.
7. Existing Tasks 01–10.6 behavior and manual workflows remain intact.

**End of roadmap.**
