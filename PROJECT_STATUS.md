# AI-PVG — Project Status Dashboard

**Project:** AI Platform Video Generator (AI-PVG)  
**Last Audit Date:** 2026-10-24  
**Auditor Role:** Lead Software Architect / Senior Laravel Engineer / QA & TPM  
**Audit Type:** G-00 Remotion Bundle Repair · G-01 Automation Control Plane · G-02 News Triage · G-03 Multi-Agent Script Pipeline

---

## 1. Readiness Taxonomy & Verification Standards

To maintain strict engineering rigor, this dashboard enforces the following state separation:

> **IMPLEMENTED ≠ TESTED ≠ INTEGRATION VERIFIED ≠ PRODUCTION READY**

- **IMPLEMENTED:** Code, classes, migrations, or UI components exist in the repository.
- **TESTED:** Automated unit/feature tests pass using mocked services or isolated test databases.
- **INTEGRATION VERIFIED:** Real external binaries (Remotion, FFmpeg/FFprobe), real local filesystems, and live/sandbox external APIs (Ollama/OpenAI, TTS, YouTube OAuth/Resumable Upload) have been executed and verified end-to-end.
- **PRODUCTION READY:** Full error recovery, queue idempotency, storage cleanup, monitoring, security hardening, and operational runbooks are verified under realistic load.

---

## 2. Git & Repository State

| Metric                 | Status / Value                                                                                       | Verification Command |
| :--------------------- | :--------------------------------------------------------------------------------------------------- | :------------------- |
| **Current Git Branch** | `main`                                                                                               | `git branch --show-current` |
| **Latest Commit**      | `edd3519 feat: complete G-02 triage scoring, clustering, and auto-greenlight`                         | `git log -1 --oneline` |
| **Working Tree State** | **Dirty (G-03 changes uncommitted)**                                                                 | `git status --short` |
| **Uncommitted Scope**  | G-03 additive `script_variants` / `script_fact_checks` migrations, script agents and orchestrator stages, directive validator, read-only Filament resources, Studio hook/directive/fact-check review, diagnostics, Remotion type additions, tests, and documentation. No commit was executed. | `git diff --stat` |

---

## 3. Environment & Runtime Status

| Component                   | Reported State                                             | Verification Level       | Notes / Evidence                                                                 |
| :-------------------------- | :--------------------------------------------------------- | :----------------------- | :------------------------------------------------------------------------------- |
| **Framework Stack**         | Laravel 12 + Filament 5 (`/auth`) + Livewire + Bootstrap 5 | **TESTED**               | Core web, admin, and studio scaffolding active.                                  |
| **Database**                | SQLite (Development Default)                               | **INTEGRATION VERIFIED** | G-01 + G-02 + G-03 additive migrations applied; dev DB preserved: `news=1`, `video_renders=0`, `youtube_publishing_states=0`; all G-01 control-plane, G-02 triage, and G-03 script-agent tables are empty. |
| **Automation Control Plane**| G-01, disabled by default                                  | **TESTED**               | 45 G-01 tests / 233 assertions; no external provider or publishing calls.       |
| **News Triage (G-02)**      | G-02, disabled by default                                  | **TESTED**               | 22 G-02 tests / 255 assertions; deterministic local scoring/clustering; zero external calls; `news_scores=0`, `story_clusters=0`, `story_cluster_news=0`. |
| **Script Agents (G-03)**    | G-03, disabled by default                                  | **TESTED**               | 36 G-03 tests / 293 assertions; deterministic local agents; zero external calls in default mode; `script_variants=0`, `script_fact_checks=0`. |
| **Remotion**                | `4.0.538` (bundler/renderer/runtime aligned)               | **INTEGRATION VERIFIED** | `bundle()` → composition selection → `renderMedia()` succeeds.                  |
| **Remotion Browser**        | Bundled Chrome headless shell                              | **INTEGRATION VERIFIED** | `remotion/node_modules/.remotion/chrome-headless-shell/win64/...` used locally.  |
| **FFmpeg / FFprobe**        | Bundled with Remotion compositor                           | **INTEGRATION VERIFIED** | `@remotion/compositor-win32-x64-msvc/{ffmpeg,ffprobe}.exe` auto-detected.        |
| **Remotion Bundle / Serve** | **FIXED (dedicated port 3099)**                            | **INTEGRATION VERIFIED** | Real MP4 produced even with a stale server listening on port 3000.               |

---

## 4. Automated Test Suite Status

- **Current Live Execution:** `241 passed` | `1176 assertions` | `0 failures` | `0 risky` (latest run).
- **G-03 Scope:** `36 passed` | `293 assertions`; every G-03 test class calls `Http::preventStrayRequests()` or an explicit `Http::fake()`; no network calls in default mode.
- **G-02 Scope:** `22 passed` | `255 assertions`; every G-02 unit/feature class calls `Http::preventStrayRequests()`; no network calls.
- **G-01 Scope:** `45 passed` | `233 assertions`; every G-01 unit/feature class calls `Http::preventStrayRequests()`.
- **Test Isolation:** `phpunit.xml` forces `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`; the dev SQLite file is not touched by tests.
- **Verification Command:** `php artisan test`.
- **Coverage Caveat:** The default suite mocks or blocks external HTTP/process boundaries. Real Remotion rendering, paid AI/TTS calls, and real YouTube uploads remain opt-in only.

---

## 5. Core Pipeline & Subsystem Status

| Subsystem / Workflow Stage                  | Implementation |  Automated Tests  |        Real Integration         | Production Ready | Current Status                  |
| :------------------------------------------ | :------------: | :---------------: | :-----------------------------: | :--------------: | :------------------------------ |
| **1. News CRUD & Categories/Sources**       |      Yes       |        Yes        |               Yes               |     Partial      | **DONE** (Historical Baseline)  |
| **2. Duplicate Detection & RSS Importer**   |      Yes       |        Yes        |               Yes               |     Partial      | **DONE** (Historical Baseline)  |
| **3. AI Script Generation (Ollama/OpenAI)** |      Yes       |   Yes (Mocked)    |        Needs Verification       |        No        | **PARTIAL**                     |
| **4. Livewire Video Studio & Theme Engine** |      Yes       |        Yes        |             Partial             |        No        | **PARTIAL**                     |
| **5. AI Voice Generation (TTS) & Sync**     |      Yes       |   Yes (Mocked)    |        Needs Verification       |        No        | **PARTIAL**                     |
| **6. Remotion Rendering Engine**            |      Yes       |        Yes        |          **Yes (real)**         |     Partial      | **INTEGRATION VERIFIED**        |
| **7. MP4 & FFprobe Media Validation**       |      Yes       |        Yes        |          **Yes (real)**         |     Partial      | **INTEGRATION VERIFIED**        |
| **8. Platform-Specific Export**             |      Yes       |        Yes        |     Uses verified source path   |        No        | **PARTIAL** (real export not run) |
| **9. YouTube OAuth & Resumable Upload**     |      Yes       | Yes (Mocked HTTP) |          **NOT VERIFIED**       |        No        | **NEEDS VERIFICATION**          |
| **10. Publishing Status & Monitoring**      |    Partial     |      Partial      |               No                |        No        | **PARTIAL**                     |
| **11. Automation Control Plane (G-01)**     |      Yes       | Yes (45 tests)    |       N/A (inert)               |        No        | **DONE (G-01 foundation)**      |
| **12. News Triage (G-02)**                  |      Yes       | Yes (22 tests)    |       N/A (inert)               |        No        | **DONE (G-02 foundation)**      |
| **13. Script Agents (G-03)**                |      Yes       | Yes (36 tests)    |       N/A (inert)               |        No        | **DONE (G-03 foundation)**      |

---

## 6. Deep-Dive Status: Rendering, MP4 & YouTube

### Remotion Rendering Status: `INTEGRATION VERIFIED (2026-10-24)`

- **Root Cause of the 404 / `Incompatible site` failure:**
  1. The previous CLI rendered from `dist/VideoScript.js` (plain TypeScript output) and hardcoded `http://localhost:3000`. It never produced a Remotion-compatible bundle nor registered a `Composition`.
  2. A stale `remotion/static-serve.cjs` process was listening on port 3000 and serving `serve/index.html`. Remotion's internal server reused that port, found a non-Remotion page, and reported `Found version: undefined - Required version: 11`.
- **Fix implemented:**
  - Added `remotion/src/Root.tsx` with a real `Composition` (`id="VideoScript"`, 1080x1920, 30 fps, `calculateMetadata`).
  - Added `remotion/src/index.ts` with `registerRoot(Root)`.
  - Rewrote `remotion/src/render-cli.ts` to call `bundle()` → `selectComposition()` → `renderMedia()`.
  - Added a dedicated Remotion port (`REMOTION_PORT`, default `3099`) so a stale process on 3000 can no longer poison the renderer.
  - Added `publicDir` support so audio/static assets are bundled.
  - Replaced the Laravel `proc_open`/`shell_exec` path with `Symfony Process` from the Remotion working directory, strict exit-code checks, and FFprobe verification (`App\Services\VideoRenderingService`).
- **Regression proof:** A render succeeded while a deliberately started `static-serve.cjs` occupied port 3000:
  - `storage/app/video_out/g00_port_guard_verified.mp4`
  - `h264`, `1080x1920`, `30/1`, `6.000000s`, `180` frames.

### MP4 Verification Status: `VERIFIED (2 real outputs)`

| Artifact | Size | Video | Audio | Duration | SHA-256 |
| :--- | ---: | :--- | :--- | :--- | :--- |
| `storage/app/video_out/g00_laravel_verified.mp4` | 35,384 B | `h264` 1080x1920 @ 30 fps, 180 frames | none (payload had no audio) | 6.000 s | `4d9babd096e810281d6dd42b265ab647d140bdb7526c257aefa61f446810dd70` |
| `storage/app/video_out/g00_audio_laravel_verified.mp4` | 283,797 B | `h264` 1080x1920 @ 30 fps, 180 frames | `aac` stereo 48 kHz, 284 frames | video 6.000 s / audio 6.016 s | `e5bd33f4fe06237bfec33483b345737b5d70a20b50130dd70ea8ed8742b4f646` |

- Both files contain a valid `mov,mp4,...` container with `probe_score: 100` and `Made with Remotion 4.0.538`.
- Audio/video start timestamps are both `0.000000`; the 16 ms audio tail is standard AAC encoder padding, not drift.
- The exact commands and JSON evidence are recorded in §10.

### YouTube Integration Status: `IMPLEMENTED & MOCK-TESTED ONLY`

- **What Exists:** OAuth 2.0 flow architecture, token storage/refresh logic, and YouTube Data API v3 resumable upload protocol implementation.
- **Test Evidence:** Verified against mocked HTTP responses (`Http::fake()`).
- **Missing Evidence:** Zero confirmed live OAuth handshakes or private test video uploads. Real uploads remain strictly opt-in behind manual confirmation gates.

---

## 7. Historical Task Audit Summary (Tasks 01 – 10.6)

- **Total Historical Tasks Tracked:** 16 (`01` through `09`, `09.1`, `10` through `10.6`)
- **Verified Done (Code + Tests):** 15 tasks
- **Failed / Incomplete:** 0 tasks
- **Integration Still Unverified:** Real paid AI/TTS providers and live YouTube upload (mocked only); real export-from-verified-render not yet executed.

> **Calculation Method Note:** We intentionally avoid reporting a single "Overall Completion Percentage" because combining mocked unit test completion with real-world media/API integration completion produces a misleading metric. Progress is tracked strictly by milestone gates:
>
> 1. _Gate 1: Core Data & Studio Architecture_ — **Complete**
> 2. _Gate 2: Real Local Media Generation (Remotion MP4 + FFprobe)_ — **Complete for video and synthetic audio**; real TTS provider audio remains pending
> 3. _Gate 3: End-to-End Automated Pipeline Execution_ — **Blocked by real TTS integration and export run**
> 4. _Gate 4: Live External Publishing (YouTube OAuth + Opt-in Upload)_ — **Pending Gate 3**

---

## 8. Known Blockers

1. **BLOCKER-01 (Resolved): Remotion `serve/index.html` HTTP 404**
   - Resolved by the `bundle()` + `Composition` + dedicated-port implementation. Verified with two real MP4s.
2. **BLOCKER-02 (P1 — High): Uncommitted G-03 Working Tree State**
   - **Impact:** Risk of accidental regression or lost work while G-04 begins.
   - **Resolution Required:** Review the G-03 diff, confirm the suite, and create a checkpoint commit once authorized. G-02 was committed as `edd3519`.
3. **BLOCKER-03 (P1 — High): Mock-Only Verification of External Boundaries**
   - AI script generation, real TTS synthesis, and YouTube uploads still rely on mocks; no single unmocked pipeline run exists.
4. **BLOCKER-04 (P2 — Medium): Real TTS Audio Not Yet Served to Remotion**
   - The audio verification used a generated 440 Hz WAV fixture. VoiceGeneration audio paths must be exposed as Remotion static assets before voice-backed MP4s can be verified.
5. **BLOCKER-05 (P2 — Medium): Stale Process Port Conflicts**
   - Dedicated port 3099 avoids the 3000 conflict, but a stale process on 3099 would still interfere. Preflight currently checks binaries and files, not port ownership.

---

## 9. Next Recommended Task

**G-03 is DONE and verified (2026-10-24). NEXT: `G-04 — Word Captions & Audio Design` (pre-flight blueprint; awaiting explicit approval).**

G-03 is implemented dark. `automation.script_agents.enabled` and the `script_agents.enabled` feature flag both default to `false`; the config-only fallback applies only when the `feature_flags` table is unavailable.

### Why G-04 Comes Next:

1. **Timed Media Inputs Exist:** G-00 provides verified MP4/audio boundaries, and G-03 now emits ordered scenes with durations and `sfx_cue` / `emotion_tone` directives.
2. **Control Plane Is Ready:** G-04 can reuse `BudgetGuard`, `DryRunContext`, `AutomationOrchestrator`, and the dual flag-gate pattern proven by G-01..G-03.
3. **No Regression:** Captions and audio mixing are additive; scripts without caption/audio data must render exactly as they do today.
4. **Truthful Media Boundary:** Timing and loudness claims must be labeled estimated or verified, never inferred from file existence.

### G-03 Completion Summary

- **Data:** additive `script_variants` and `script_fact_checks`; no destructive changes to `video_scripts`. Dev DB remains `news=1`, `video_renders=0`, `youtube_publishing_states=0`, with both G-03 tables at `0` rows.
- **Pipeline:** `script_agents.pipeline` stages `generate_variants` → `compose_script` → `fact_check_script`; exactly three distinct grounded hooks with deterministic `hook_index` `1..3` and explicit `source_news_ids`.
- **Idempotency:** active window `cluster-{id}:g03-v1` or `news-{id}:g03-v1`; reruns return the existing run/script/variants and never duplicate rows.
- **Directives:** strict per-scene schema (`camera_motion`, `b_roll_query`, `highlight_text`, `sfx_cue`, `emotion_tone`, `duration_seconds`); total duration must stay within ±5s of target; legacy scenes without directives pass through unchanged.
- **Fact checks:** every claim is persisted with `supported|unsupported|uncertain`, explicit `source_news_ids`, and an evidence snippet. Any blocking verdict keeps the script in `Draft` and opens a `ScriptFactCheck` approval gate; approved scripts are never overwritten by automation.
- **Safety:** config + DB flag must both be enabled; local deterministic agents are the default; optional external AI requires explicit opt-in, `BudgetGuard::authorize()`, `DryRunContext`, and an `ai_usage_ledger` entry with purpose `script` or `critic`. Dry-run returns labeled plans with zero paid calls and zero production mutations.
- **UI:** read-only `ScriptVariantResource` / `ScriptFactCheckResource`; Studio hook selection, in-memory directive preview/diff, and fact-check warning badges with source attribution. No publishing controls.
- **Verification:** G-03 `36 passed / 293 assertions`; full suite `241 passed / 1176 assertions / 0 failures / 0 risky`; build and Pint pass; diagnostics reports `Script agent tables: 2/2 OK` and all-zero dev counts.

### G-04 Pre-Flight Blueprint (proposed, not implemented)

- **Objective:** add word-level captions, music ducking, SFX placement, and loudness normalization on top of the G-03 scene directives, without changing legacy rendering.
- **Data (additive only):**
  - `caption_tracks`: `video_script_id`, language, source (`estimated|verified`), style, `word_timings` JSON, status, `verified_at`, timestamps.
  - `audio_assets`: kind (`music|sfx`), storage path, license/source metadata, duration, loudness, checksum, timestamps.
  - Nullable `voice_generations.word_timings` for provider-returned or locally aligned timings.
- **Services and stages:**
  - `WordTimingService` — derive monotonic estimated timings from narration/TTS durations; label them `estimated`; accept `verified` timings only from a real TTS/alignment result.
  - `AudioMixService` — build an explicit FFmpeg filter graph for music bed, sidechain ducking (target ≈18 dB), SFX cues mapped from `scenes[].directives.sfx_cue`, and loudness normalization.
  - `CaptionTrackBuilder` / `CaptionStyleResolver` — bounded word/line lengths, RTL-aware language handling, and deterministic style presets.
  - `captions.pipeline` stages `build_captions` → `mix_audio`, registered through `PipelineRegistry`.
- **Execution safety:**
  - Gate behind `automation.captions.enabled=false` plus a `captions.enabled` DB flag; both required when the flag table exists.
  - Dry-run returns labeled caption/audio plans and performs no paid call, no asset download, and no production render mutation.
  - Any future external alignment call must be explicitly approved, budget-authorized, and ledgered; G-04 default remains local/estimated.
  - Missing tables, missing FFmpeg, or absent audio must fail closed or truthfully fall back to video-only.
- **UI:** read-only Filament views for caption tracks and audio assets; Studio caption/timing preview with `estimated` vs `verified` labels and ducking/SFX inspection; no publishing controls.
- **Tests:** `Http::preventStrayRequests()` or explicit `Http::fake()` in every G-04 class; monotonic timing validation; caption length/RTL cases; ducking/filter-graph assertions; FFmpeg fixture tests skipped when the binary is absent; legacy script/render compatibility; dry-run zero-call proof; flag-off and missing-table fail-closed behavior.
- **Acceptance:** caption timings are monotonic and end within audio duration; estimated vs verified is always labeled; ducking and loudness match configured targets within tolerance; video-only fallback is truthful; legacy renders are unchanged; zero paid calls in dry-run.
- **Out of scope:** visual assets (G-05), multi-format fan-out (G-06), real TTS provider verification, publishing, paid alignment APIs, and any automatic public action.
- **Rollback:** set `automation.captions.enabled=false` and the DB flag false; Remotion continues to render the existing video-only/text path.
- **Risks:** timing drift, RTL line breaking, filter-graph regressions, and loudness over-processing; mitigate with monotonic clamps, golden fixtures, opt-in FFmpeg integration tests, and conservative normalization.

---

## 10. G-00 Evidence Appendix

### 10.1 Silent Video Render (Laravel command)

```bash
php artisan ai-pvg:verify-mp4 --execute \
  --output=storage/app/video_out/g00_laravel_verified.mp4
```

Result: `MP4 VERIFIED`; `h264`, `1080x1920`, `30 fps`, `6 s`, `180` frames, `35,384` bytes.

### 10.2 Audio-Backed Render (Laravel command)

```bash
php artisan ai-pvg:verify-mp4 --execute \
  --payload=remotion/payload_audio.json \
  --output=storage/app/video_out/g00_audio_laravel_verified.mp4
```

Result: `MP4 VERIFIED`; video `h264` 1080x1920 @ 30 fps; audio `aac` stereo 48 kHz; video 6.000 s; audio 6.016 s; `283,797` bytes.

### 10.3 FFprobe commands

```bash
"remotion/node_modules/@remotion/compositor-win32-x64-msvc/ffprobe.exe" \
  -v error -show_format -show_streams -of json \
  storage/app/video_out/g00_laravel_verified.mp4

"remotion/node_modules/@remotion/compositor-win32-x64-msvc/ffprobe.exe" \
  -v error -show_format -show_streams -of json \
  storage/app/video_out/g00_audio_laravel_verified.mp4
```

### 10.4 Test gate

```bash
php artisan test
# 138 passed (392 assertions), 0 failures, 0 risky
```

### 10.5 Preserved development database

```text
news=1 video_renders=0 youtube_publishing_states=0
```

No `migrate:fresh`, `db:wipe`, record mutation, paid AI call, or live YouTube upload was executed.

---

## 11. G-01 Evidence Appendix

### 11.1 Scope and migration evidence

- **Migration range:** `2026_10_24_120000`–`2026_10_24_120600` (seven additive migrations).
- **Tables:** `automation_runs`, `automation_stage_runs`, `ai_usage_ledger`, `budget_periods`, `approval_gates`, `provider_health_events`, `feature_flags`.
- **Runtime defaults:** `AUTOMATION_ENABLED=false`, `AUTOMATION_DRY_RUN=true`.
- **Control services:** `BudgetGuard`, `CircuitBreaker`, `DryRunContext`, `ApprovalService`, `FeatureFlagService`, `PipelineRegistry`, `AutomationOrchestrator`.
- **Interface:** read-only `AutomationRunResource` and `ApprovalGateResource` (list/view only; create/edit/delete denied; missing-table fail-closed).
- **Operations:** idempotent `ai-pvg:automation:tick`, scheduler entry, and read-only `ai-pvg:diagnostics` extensions.

### 11.2 Test gate

```text
$ php artisan test
Tests: 183 passed (625 assertions)
Duration: 53.96s
```

- G-01 targeted suite: `45 passed / 233 assertions / 0 failures`.
- Every G-01 unit/feature class uses `Http::preventStrayRequests()`.
- No real provider, upload, or publishing action was executed.

### 11.3 Diagnostics output (exact, read-only)

```text
=== AI-PVG DIAGNOSTICS (READ-ONLY) ===
Migrations table: OK
Queue connection: database
Remotion CLI: YES
Chrome headless: YES
FFprobe: YES
FFmpeg: YES
Output dir writable: YES
AI provider configured: NO
TTS provider: mock
YouTube configured: NO
DB records: news=1; scripts=0; renders=0; youtube_publishing_states=0
Automation enabled: NO (dry-run: YES)
Automation tables: 7/7 OK
Automation runs: total=0; pending=0; running=0; paused=0; completed=0; failed=0; cancelled=0
Automation stages: total=0
AI usage ledger: entries=0; cost_micros=0
Budget: period=not-initialized; limit_micros=5000000; spent_micros=0; reserved_micros=0; remaining_micros=5000000; breaker=closed
Approvals: pending=0; approved=0; rejected=0; expired=0; dual_public_publish=NO
Provider health: events_24h=0; providers=none; last=none
Feature flags: total=0; enabled=0
=== END DIAGNOSTICS ===
```

### 11.4 Development database preservation

```json
{"news":1,"video_renders":0,"youtube_publishing_states":0,"automation_runs":0,"automation_stage_runs":0,"ai_usage_ledger":0,"budget_periods":0,"approval_gates":0,"provider_health_events":0,"feature_flags":0}
```

### 11.5 Safety attestations

- No `migrate:fresh`, `db:wipe`, or seeder was run against the dev SQLite database.
- No existing row was mutated, truncated, or deleted.
- No external network call, paid provider call, live YouTube upload, or public publish occurred during verification.
- Automation remains disabled and dry-run remains enabled by default.

---

## 12. G-02 Evidence Appendix

### 12.1 Scope and migration evidence

- **Migration range:** `2026_10_24_130000`–`2026_10_24_130200` (three additive migrations).
- **Tables:** `news_scores`, `story_clusters`, `story_cluster_news`.
- **Runtime defaults:** `AUTOMATION_TRIAGE_ENABLED=false`; feature flag `triage.news_scoring_enabled`; `AUTOMATION_DRY_RUN=true`.
- **Services:** `NewsScoringService`, `TextSimilarityService`, `StoryClusteringService`, `AutoGreenlightService`, `TriageGate`.
- **Stages:** `ScoreNewsStage`, `ClusterStoriesStage`, `AutoGreenlightStage`.
- **UI:** read-only `NewsScoreResource` / `StoryClusterResource`; Studio Triage panel with score breakdowns, source attribution, and admin-only “Generate script.”
- **Jobs/scheduler:** no autonomous G-02 job or scheduler entry was added; execution is manual/flag-gated through the orchestrator stages and Studio action.

### 12.2 Determinism, grounding, and safety

- Scoring uses only local data and stores component scores, weighted contributions, thresholds, evidence, explanations, `scored_at`, and `breakdown` JSON.
- Clustering uses deterministic token/TF-IDF cosine similarity; no embeddings, HTTP, or paid calls.
- Persisted clusters are limited to 3–5 distinct sources. Fact-sheet claims, bullets, entities, and timeline items with missing or invalid `source_news_ids` are stripped.
- Auto-greenlight is idempotent by cluster active window (`cluster-{id}`); reruns return the existing script and never create a duplicate.
- Review-band scores open an idempotent `ApprovalGate` of type `AutoGreenlight` through `ApprovalService`; below-band scores take no downstream action.
- Dry-run records `planned_script` in `AutomationRun.meta` and creates no `VideoScript`.
- Threshold guardrails force auto-greenlight to `>= 85` and review to `60–84`, even if an environment value is unsafe.
- No G-02 code imports or calls a paid AI/embedding provider. There are therefore no `BudgetGuard::authorize()` calls, no `ai_usage_ledger` rows, and no external HTTP requests.

### 12.3 Test gate

```text
$ php artisan test
Tests: 205 passed (883 assertions)
Duration: 57.69s
```

```text
$ php artisan test tests/Feature/NewsScoringServiceTest.php \
    tests/Feature/StoryClusteringServiceTest.php \
    tests/Feature/AutoGreenlightServiceTest.php \
    tests/Feature/TriageStagesTest.php \
    tests/Feature/TriageFilamentResourceTest.php \
    tests/Feature/TriageResourceMissingTableTest.php \
    tests/Feature/StudioTriageTest.php \
    tests/Feature/TriageDiagnosticsTest.php
Tests: 22 passed (255 assertions)
Duration: 3.75s
```

- Every G-02 unit/feature class calls `Http::preventStrayRequests()`; tests also assert no requests were sent.
- Full suite baseline before G-02: `183 passed / 625 assertions`; after G-02: `205 passed / 883 assertions`.

### 12.4 Diagnostics output (exact, read-only)

```text
=== AI-PVG DIAGNOSTICS (READ-ONLY) ===
Migrations table: OK
Queue connection: database
Remotion CLI: YES
Chrome headless: YES
FFprobe: YES
FFmpeg: YES
Output dir writable: YES
AI provider configured: NO
TTS provider: mock
YouTube configured: NO
DB records: news=1; scripts=0; renders=0; youtube_publishing_states=0
Automation enabled: NO (dry-run: YES)
Automation tables: 7/7 OK
Automation runs: total=0; pending=0; running=0; paused=0; completed=0; failed=0; cancelled=0
Automation stages: total=0
AI usage ledger: entries=0; cost_micros=0
Budget: period=not-initialized; limit_micros=5000000; spent_micros=0; reserved_micros=0; remaining_micros=5000000; breaker=closed
Approvals: pending=0; approved=0; rejected=0; expired=0; dual_public_publish=NO
Provider health: events_24h=0; providers=none; last=none
Feature flags: total=0; enabled=0
Triage enabled: NO (flag=triage.news_scoring_enabled)
Triage tables: 3/3 OK
Triage data: news_scores=0; story_clusters=0; story_cluster_news=0
=== END DIAGNOSTICS ===
```

### 12.5 Development database preservation

```text
news=1
video_renders=0
youtube_publishing_states=0
news_scores=0
story_clusters=0
story_cluster_news=0
automation_runs=0
approval_gates=0
ai_usage_ledger=0
```

### 12.6 Safety attestations

- Only the three additive G-02 migrations were applied to the dev SQLite database.
- No `migrate:fresh`, `db:wipe`, or seeder was run against the dev database.
- The existing `news=1` row was not scored, updated, deleted, or otherwise mutated outside the isolated `:memory:` test database.
- No external network call, paid provider call, live YouTube upload, public publish, or real model invocation occurred.

---

## 13. G-03 Evidence Appendix

### 13.1 Scope and migration evidence

- **Migration range:** `2026_10_24_140000`–`2026_10_24_140100` (two additive migrations).
- **Tables:** `script_variants`, `script_fact_checks`. No column was added to or removed from `video_scripts`.
- **Runtime defaults:** `automation.script_agents.enabled=false`; `automation.script_agents.use_ai=false`; feature flag `script_agents.enabled` absent/disabled; `AUTOMATION_DRY_RUN=true` remains the global default.
- **Gate semantics:** config and DB flag must both be enabled. When the `feature_flags` table is unavailable, the gate falls back to the config value only; every other failure path is closed.
- **Services:** `ScriptSource`, `ScriptSourceFactory`, `ScriptAgentsGate`, `ScriptAgentAiClient`, `DirectiveValidator`, `HookSpecialistAgent`, `NarrativeArchitectAgent`, `FactCheckerAgent`, `ScriptPipelineService`.
- **Stages:** `GenerateScriptVariantsStage`, `ComposeScriptStage`, `FactCheckScriptStage` registered under pipeline `script_agents.pipeline`; `ResolvesScriptSource` resolves a `StoryCluster` or `News` subject.
- **UI:** read-only `ScriptVariantResource` / `ScriptFactCheckResource`; Studio hook selection, in-memory directive preview/diff, and fact-check verdict badges with source attribution. No publishing controls.
- **Jobs/scheduler:** no autonomous G-03 job or scheduler entry was added; execution remains manual/flag-gated through the orchestrator.
- **Remotion:** `remotion/src/VideoScript.tsx` gained optional `directives`, `claim`, and `source_news_ids` fields; legacy scenes without them render unchanged.

### 13.2 Pipeline, grounding, and safety

- **Hooks:** exactly three distinct grounded hooks per run; deterministic `hook_index` `1..3`; each variant stores explicit `source_news_ids`; a duplicate or short hook set throws and is not persisted.
- **Idempotency:** active window `cluster-{id}:g03-v1` or `news-{id}:g03-v1`; reruns return the existing run/script/variants and create no duplicate rows.
- **Directives:** strict per-scene schema validated by `DirectiveValidator` (`camera_motion`, `b_roll_query`, `highlight_text`, `sfx_cue`, `emotion_tone`, `duration_seconds`); contiguous `order`; total duration within target ±5s; mixed presence is rejected; legacy scenes without directives pass through unchanged.
- **Fact checking:** lexical/deterministic verification against explicit member `news_id` evidence; every claim is persisted with `supported|unsupported|uncertain`, `source_news_ids`, and an evidence snippet. Any blocking verdict keeps the script in `Draft` and opens an `ApprovalGate` of type `ScriptFactCheck` through `ApprovalService`; unsupported claims never auto-approve.
- **Approved-script protection:** automation never overwrites an approved `VideoScript`; `run.meta.script_protected` and the blocked outcome are recorded.
- **Optional external AI:** only when `automation.script_agents.use_ai=true` and never during dry-run; every call passes `BudgetGuard::authorize()`, uses `DryRunContext`, and settles into `ai_usage_ledger` with purpose `Script` or `Critic`.
- **Dry-run:** returns labeled `planned_variants`, `planned_scenes`, and `planned_fact_checks` in `AutomationRun.meta`; zero paid calls and zero production `VideoScript` mutations.
- **Missing tables:** Studio and Filament resources fail closed with an explicit unavailable state; no query is attempted against absent G-03 tables.

### 13.3 Test gate

- **G-03 suite:** `36 passed` / `293 assertions` / `0 failures` / `0 risky`.
- **Full suite:** `241 passed` / `1176 assertions` / `0 failures` / `0 risky`.
- **Classes:** `DirectiveValidatorTest`, `ScriptAgentsGateTest`, `HookSpecialistAgentTest`, `ScriptSourceFactoryTest`, `FactCheckerAgentTest`, `ScriptPipelineServiceTest`, `ScriptStagesTest`, `ScriptFilamentResourceTest`, `ScriptResourceMissingTableTest`, `StudioScriptAgentsTest`, `ScriptDiagnosticsTest`, plus the shared `tests/Support/BuildsScriptFixtures.php` fixture trait.
- **Network gate:** every G-03 test class calls `Http::preventStrayRequests()` or an explicit `Http::fake()`; default-mode tests assert zero requests were sent.

### 13.4 Diagnostics output (exact, read-only)

```text
Script agents enabled: NO (flag=script_agents.enabled; use_ai=NO)
Script agent tables: 2/2 OK
Script agent data: script_variants=0; hooks=0; script_fact_checks=0; pending_fact_check_gates=0
```

### 13.5 Development database preservation

```text
news=1
video_renders=0
youtube_publishing_states=0
automation_runs=0
automation_stage_runs=0
ai_usage_ledger=0
budget_periods=0
approval_gates=0
provider_health_events=0
feature_flags=0
news_scores=0
story_clusters=0
story_cluster_news=0
script_variants=0
script_fact_checks=0
```

### 13.6 Build and style verification

- `npm run build` → Vite `7.3.7`, 58 modules transformed, `public/build/assets/app-BI1te6TZ.css` 52.00 kB, `public/build/assets/app-DMsN-rLE.js` 51.52 kB, built in 1.91s.
- `vendor/bin/pint --test` on the G-03 PHP paths → no style violations.
- `vendor/bin/pint` was executed during development; unrelated pre-existing formatting changes were reverted so the G-03 change set stays scoped.

### 13.7 Safety attestations

- Only the two additive G-03 migrations were applied to the dev SQLite database.
- No `migrate:fresh`, `db:wipe`, or seeder was run against the dev database.
- The existing `news=1` row and all G-01/G-02 tables were left untouched; `script_variants` and `script_fact_checks` contain `0` rows.
- No external network call, paid provider call, live YouTube upload, public publish, or real model invocation occurred during G-03 implementation or verification.
- G-03 is implemented dark and is **DONE**; no G-04 work has started.
