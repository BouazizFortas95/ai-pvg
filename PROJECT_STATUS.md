# AI-PVG — Project Status Dashboard

**Project:** AI Platform Video Generator (AI-PVG)  
**Last Audit Date:** 2026-10-24  
**Auditor Role:** Lead Software Architect / Senior Laravel Engineer / QA & TPM  
**Audit Type:** G-00 Remotion Bundle Repair & G-01 Automation Control Plane Verification

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
| **Latest Commit**      | `96c5040 feat: Add diagnostics and MP4 verification commands, enhance YouTube publishing integration` | `git log -1 --oneline` |
| **Working Tree State** | **Dirty (G-00 + G-01 changes uncommitted)**                                                          | `git status --short` |
| **Uncommitted Scope**  | Remotion bundle fix, Laravel render invocation hardening, G-01 automation control plane, tests, and documentation. | No `git reset`, `git checkout`, or commit was executed. |

---

## 3. Environment & Runtime Status

| Component                   | Reported State                                             | Verification Level       | Notes / Evidence                                                                 |
| :-------------------------- | :--------------------------------------------------------- | :----------------------- | :------------------------------------------------------------------------------- |
| **Framework Stack**         | Laravel 12 + Filament 5 (`/auth`) + Livewire + Bootstrap 5 | **TESTED**               | Core web, admin, and studio scaffolding active.                                  |
| **Database**                | SQLite (Development Default)                               | **INTEGRATION VERIFIED** | G-01 additive migrations applied; dev DB preserved: `news=1`, `video_renders=0`, `youtube_publishing_states=0`; all seven new control-plane tables empty. |
| **Automation Control Plane**| G-01, disabled by default                                  | **TESTED**               | 45 G-01 tests / 233 assertions; no external provider or publishing calls.       |
| **Remotion**                | `4.0.538` (bundler/renderer/runtime aligned)               | **INTEGRATION VERIFIED** | `bundle()` → composition selection → `renderMedia()` succeeds.                  |
| **Remotion Browser**        | Bundled Chrome headless shell                              | **INTEGRATION VERIFIED** | `remotion/node_modules/.remotion/chrome-headless-shell/win64/...` used locally.  |
| **FFmpeg / FFprobe**        | Bundled with Remotion compositor                           | **INTEGRATION VERIFIED** | `@remotion/compositor-win32-x64-msvc/{ffmpeg,ffprobe}.exe` auto-detected.        |
| **Remotion Bundle / Serve** | **FIXED (dedicated port 3099)**                            | **INTEGRATION VERIFIED** | Real MP4 produced even with a stale server listening on port 3000.               |

---

## 4. Automated Test Suite Status

- **Current Live Execution:** `183 passed` | `625 assertions` | `0 failures` | `0 risky` (latest run 53.96s).
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
2. **BLOCKER-02 (P1 — High): Uncommitted Working Tree State**
   - **Impact:** Risk of accidental regression or lost work while G-02 begins.
   - **Resolution Required:** Review the diff, run the suite, and create a checkpoint commit once authorized.
3. **BLOCKER-03 (P1 — High): Mock-Only Verification of External Boundaries**
   - AI script generation, real TTS synthesis, and YouTube uploads still rely on mocks; no single unmocked pipeline run exists.
4. **BLOCKER-04 (P2 — Medium): Real TTS Audio Not Yet Served to Remotion**
   - The audio verification used a generated 440 Hz WAV fixture. VoiceGeneration audio paths must be exposed as Remotion static assets before voice-backed MP4s can be verified.
5. **BLOCKER-05 (P2 — Medium): Stale Process Port Conflicts**
   - Dedicated port 3099 avoids the 3000 conflict, but a stale process on 3099 would still interfere. Preflight currently checks binaries and files, not port ownership.

---

## 9. Next Recommended Task

**NEXT: `G-02 — News Scoring, Clustering & Auto-Greenlight` (pre-flight blueprint; awaiting explicit approval).**

G-01 is complete and verified. G-02 builds on the control plane while keeping paid providers and public publishing disabled by default.

### Why G-02 Comes Next:

1. **Triage Before Generation:** The importer still treats every article equally. Scoring and clustering are required before multi-agent scripts or render fan-out can be prioritized safely.
2. **Control Plane Is Ready:** G-02 can reuse the run/stage ledger, budget guard, dry-run context, feature flags, and approval gates delivered by G-01.
3. **No Regression:** Existing Tasks 01–10.6 behavior remains the fallback; G-02 jobs are additive and disabled by default.

### G-02 Pre-Flight Blueprint (proposed, not implemented)

- **Data (additive only):**
  - `news_scores`: unique `news_id`; component scores; indexed `composite_score`; `breakdown` JSON; `scored_at`.
  - `story_clusters`: canonical title/summary, `fact_sheet` JSON, status, confidence, time window, source count.
  - `story_cluster_news`: cluster/news foreign keys, similarity, `is_primary`, unique cluster/news pair.
- **Services:**
  - `Scoring\NewsScoringService` — deterministic weighted score with stored breakdown; velocity, keyword heat, novelty, source diversity, recency.
  - `Clustering\StoryClusteringService` — local token/TF-IDF similarity by default; optional embedding provider behind an interface; 3–5 source cluster cap; every fact-sheet claim references at least one `news_id`.
  - `Greenlight\AutoGreenlightService` — threshold bands; draft `VideoScript` creation; approval gate for the review band; idempotent run linkage.
- **Execution safety:**
  - Create `automation_runs` with the news/cluster subject and a stable active window.
  - Any optional paid call must pass `BudgetGuard::authorize()`, respect `DryRunContext`, and record usage in `ai_usage_ledger`.
  - Dry-run performs zero paid calls and labels synthetic/planned output.
  - Feature flag off by default; jobs and scheduler entries are inert until explicitly enabled.
- **UI:** read-only `NewsScoreResource` and `StoryClusterResource`; Studio “Triage” panel with score breakdown, source attribution, and a “Generate script” action. No publishing controls.
- **Tests:** deterministic scoring fixtures, clustering/no-false-merge fixtures, 3–5 source cap, threshold bands, duplicate-script prevention, idempotent re-runs, dry-run zero-call proof, feature-flag-off behavior. All tests use `Http::preventStrayRequests()` or explicit `Http::fake()`.
- **Acceptance:** reproducible score breakdown; 3–5 source cluster cap; no duplicate scripts; auto-greenlight never bypasses HITL gates; zero paid calls in dry-run.
- **Out of scope:** paid trend APIs, script agents (G-03), captions (G-04), assets (G-05), fan-out (G-06), and publishing.
- **Rollback:** disable G-02 jobs/flags; historical scores and clusters remain inert.
- **Risks:** false merges; mitigate with a confidence threshold, source attribution, and a HITL review band.

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
