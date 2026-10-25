# AI-PVG — Project Status Dashboard

**Project:** AI Platform Video Generator (AI-PVG)

**Last Audit Date:** 2026-10-25

**Auditor Role:** Lead Software Architect / Senior Laravel Engineer / QA & TPM

**Audit Type:** G-00..G-06 Hyper-Automation MVP · Phase H Publishing, Co-Pilot & Autopilot Gates

**Final Status:** **DONE — MVP Production-Ready Blueprint Complete**

---

## 1. Readiness Taxonomy & Verification Standards

> **IMPLEMENTED ≠ TESTED ≠ INTEGRATION VERIFIED ≠ PRODUCTION READY**

- **IMPLEMENTED:** Code, classes, migrations, and UI components exist.
- **TESTED:** Automated tests pass against an isolated `:memory:` SQLite database with external boundaries mocked.
- **INTEGRATION VERIFIED:** Real local binaries/filesystems or live external APIs have been exercised end-to-end.
- **PRODUCTION READY:** Recovery, queue idempotency, storage lifecycle, monitoring, security hardening, and runbooks are verified under realistic load.

The delivered MVP is **implemented and tested**. External live boundaries (paid AI/TTS providers and real YouTube OAuth/upload) remain opt-in, mocked in the test suite, and **not live-integration verified**. This dashboard does not treat mocked success as live verification.

---

## 2. Git & Repository State

| Metric | Status / Value | Verification Command |
| :--- | :--- | :--- |
| **Current branch** | `main` | `git branch --show-current` |
| **Phase H foundation checkpoint** | `fdf950c feat(automation): complete G-05 visual asset harvester scene props remotion dynamic` | `git show -s --oneline fdf950c` |
| **Phase H completion delta** | Read-only `PublishingStateResource` / `CoPilotSessionResource`, Studio publishing/Co-Pilot oversight, strict `PublishingGate` + dual-admin guard, comprehensive autopilot gate, diagnostics, tests, and documentation. | `git show --stat HEAD` after the completion commit |
| **Working tree** | Uncommitted dynamic RSS category/import enhancement (resolver, broad `rss:import`, tests, docs); dev SQLite remains ignored and uncommitted. | `git status --short` |
| **Database file** | `database/database.sqlite` is ignored by Git and was not staged. | `git check-ignore database/database.sqlite` |

The earlier G-05/G-06 commit message did not name Phase H; the checkpoint hash is authoritative. No history rewrite was performed during the Phase H completion pass.

---

## 3. Environment & Runtime Status

| Component | Reported State | Verification Level | Notes |
| :--- | :--- | :--- | :--- |
| **Framework** | Laravel 12.70.1, PHP 8.2.12, Filament 5, Livewire, Vite 7.3.7 | **TESTED** | Admin panel at `/auth`; Studio at `/studio`. |
| **Database** | SQLite development database | **INTEGRATION VERIFIED (local)** | Additive migrations applied through Phase H. Existing `news=1` row preserved. |
| **Automation control plane (G-01)** | Disabled by default | **TESTED** | Budget guard, breaker, dry-run, approvals, feature flags, ledger. |
| **News triage (G-02)** | Disabled by default | **TESTED** | Deterministic offline scoring/clustering; no paid trend APIs. |
| **Script agents (G-03)** | Disabled by default | **TESTED** | Grounded hooks, directives, fact checks, approval gating. |
| **Captions/audio (G-04)** | Disabled by default | **TESTED** | Caption tracks, ducking/SFX filter planning, truthful timing labels. |
| **Visual assets (G-05)** | Disabled by default | **TESTED** | Allowlisted harvesting and Remotion scene props; no arbitrary scraping. |
| **Format fan-out (G-06)** | Disabled by default | **TESTED** | Preset validators, planned render/thumbnail/metadata variants; dry-run only. |
| **Publishing / Co-Pilot (Phase H)** | Disabled by default | **TESTED** | Dual-admin guard, admin-only Co-Pilot audit, global autopilot preflight. |
| **Remotion / FFmpeg / FFprobe** | Bundled runtime available | **INTEGRATION VERIFIED** | Real MP4s verified during G-00; no paid or public action in Phase H. |
| **YouTube live upload** | Configured: NO | **NOT LIVE-VERIFIED** | Resumable upload code and mocked HTTP tests only. |

---

## 4. Automated Test Suite Status

- **Latest full run:** `281 passed` · `2371 assertions` · `0 failures` · `0 risky`.
- **Phase H suite:** `12 passed` · `53 assertions` · `0 failures`.
- **Dry-run walkthrough suite:** `1 passed` · `864 assertions` · `0 failures` · `0 risky`.
- **Live tech import suite:** `3 passed` · `32 assertions` · `0 failures`.
- **Dynamic category/import suite:** `5 passed` · `47 assertions` · `0 failures`.
- **Manual triage command suite:** `6 passed` · `67 assertions` · `0 failures`.
- **RSS → triage handshake suite:** `4 passed` · `24 assertions` · `0 failures`.
- **Isolation:** `phpunit.xml` forces SQLite `:memory:`; tests never touch the dev database.
- **Network discipline:** every Phase H test and the walkthrough call `Http::preventStrayRequests()`; no YouTube, provider, crawler, or paid call is made.
- **Verification command:** `php artisan test`.

**Build verification:** `npm run build` → Vite 7.3.7, 58 modules transformed, `public/build/assets/app-BI1te6TZ.css` 52.00 kB, `public/build/assets/app-DMsN-rLE.js` 51.52 kB, built in **1.90s**.

**Style verification:** full-repository `vendor/bin/pint --test` → **passed**.

---

## 5. Subsystem Status

| # | Subsystem | Implementation | Automated Tests | Live Integration | Status |
|---|-----------|:---:|:---:|:---:|:---|
| 1 | News CRUD, sources, dynamic RSS categories, duplicate detection | Yes | Yes | Yes (historical) | **DONE** |
| 2 | AI script generation (legacy single prompt) | Yes | Mocked | Not live-verified | **PARTIAL** external boundary |
| 3 | TTS and voice generation | Yes | Mocked | Not live-verified | **PARTIAL** external boundary |
| 4 | Remotion rendering + FFprobe verification | Yes | Yes | **Yes (real MP4s)** | **INTEGRATION VERIFIED** |
| 5 | Export presets and media validation | Yes | Yes | Partial | **PARTIAL** |
| 6 | G-00 Remotion/MP4 baseline | Yes | Yes | **Yes** | **DONE** |
| 7 | G-01 automation control plane | Yes | Yes | Inert by default | **DONE** |
| 8 | G-02 triage scoring/clustering/greenlight | Yes | Yes | Inert by default | **DONE** |
| 9 | G-03 multi-agent scripts/directives/fact checks | Yes | Yes | Inert by default | **DONE** |
| 10 | G-04 captions, audio design, ducking | Yes | Yes | Inert by default | **DONE** |
| 11 | G-05 licensed visual assets + Remotion props | Yes | Yes | Inert by default | **DONE** |
| 12 | G-06 format fan-out, thumbnails, A/B metadata | Yes | Yes | Inert/dry-run | **DONE** |
| 13 | Phase H publishing guard + Co-Pilot + autopilot gates | Yes | Yes | No live upload | **DONE** |

---

## 6. Phase H — Delivered Scope

### 6.1 Additive data

- `publishing_approval_sessions` — script-linked approval session with two approver IDs, timestamps, status, and rejection reason.
- `co_pilot_review_sessions` — append-only administrator review records for scripts, renders, and assets.
- No destructive schema changes. Existing `youtube_publishing_states` remains private-by-default and upload status is not treated as public visibility.

### 6.2 Public-publish guard

`App\Services\Publishing\YouTubePublishService` performs an authorization check only and never performs an HTTP upload:

1. `PublishingGate` requires `automation.publishing.enabled=true` **and** the `publishing.enabled` database flag when the flag table exists.
2. An approved `PublishingApprovalSession` or an approved `ApprovalGateType::PublicPublish` gate must exist for the script.
3. Both approver IDs must be present, distinct, and belong to existing users with the `admin` role.
4. Missing tables, a disabled gate, pending/rejected approval, the same admin twice, non-admin approvers, or no approval all fail closed.
5. No code path automates a public upload. Live YouTube upload remains manual, opt-in, and test-mocked.

### 6.3 Co-Pilot review inbox

`App\Services\Publishing\CoPilotReviewService`:

- Rejects non-admin reviewers with `AuthorizationException`.
- Accepts only `script`, `render`, or `asset` targets and `approve`/`reject` actions.
- Writes append-only `co_pilot_review_sessions` audit rows.
- Never publishes, uploads, renders, or mutates the reviewed subject.

### 6.4 Autopilot preflight

`App\Services\Automation\AutopilotGateService` defaults to **BLOCKED** and requires all of:

- G-01 master automation switch;
- G-02 triage gate;
- G-03 script-agents gate;
- G-04 captions config + flag;
- G-05 assets config + flag;
- G-06 formats config + flag;
- positive remaining monthly budget;
- budget circuit breaker in `closed` state.

Any missing gate, exhausted budget, open/half-open breaker, or missing budget table blocks execution and returns a reason list.

### 6.5 UI and diagnostics

- `PublishingStateResource` — read-only list/view of publishing approvals; no create/edit/delete; fails closed if tables are missing.
- `CoPilotSessionResource` — read-only list/view of Co-Pilot reviews; no create/edit/delete; fails closed if tables are missing.
- Studio dashboard — read-only Phase H panel showing gate state, approval counts, distinct-admin approvals, Co-Pilot review counts, and recent audit rows. No publish/upload buttons.
- `ai-pvg:diagnostics` — reports publishing/Co-Pilot gate state, table availability, data counts, dual-control enforcement, and autopilot block reasons without mutating data.

### 6.6 Dry-run walkthrough and operator runbook

- `tests/Feature/EndToEndAutomationDryRunWalkthroughTest.php` exercises the real G-01..G-06 services and orchestration stages against isolated `:memory:` SQLite, then asserts zero HTTP calls, zero paid spend, and zero production media/publishing rows.
- `OPERATOR_RUNBOOK.md` documents local setup (web server, Vite, queue worker, `/auth`, `/studio`), dry-run env/feature-flag configuration, verification commands, and the real local Remotion render command.
- The G-05 path was corrected during the walkthrough: `ScenePropBuilder` now maps verified `SceneAsset` records to their `VisualAsset` storage path and attribution, `RemotionPropInjector` emits the full props object as `sceneProps`, and `remotion/src/VideoScript.tsx` destructures `visualAssets`.
- A real local render was executed with `php artisan ai-pvg:verify-mp4 --execute` and passed FFprobe verification (`h264`, `1080x1920`, `30 fps`, 6 seconds).

### 6.7 RSS news ingestion and dynamic categories

- `php artisan rss:import` is now the broad synchronous importer. It fetches every available article from every active `NewsSource` with a non-empty RSS URL, with no category filter and no default item cap. Optional `--source`, `--limit`, and `--per-feed` flags remain available for operator scoping.
- Imports store items as `ready`, so `ai-pvg:triage --run --cluster` can score and cluster them immediately. Queued imports still trigger the gated post-import triage handshake.
- RSS `<category>`, Atom `term`/`label`, Dublin Core `subject`, and MRSS `keywords` values are parsed. Resolution priority is specific article tag → generic article tag → content detection → specific/generic feed category → configured fallback.
- Existing categories are reused case-insensitively by name or slug. Aliases in `config/news_categories.php` canonicalize common variants (`AI` → `Artificial Intelligence`, `cybersecurity` → `Security`). Unknown labels are auto-created as active `news_categories` rows, so a missing category never drops an article.
- Content-only detection uses weighted keyword sets and requires `NEWS_CATEGORY_DETECTION_MIN_SCORE` (default `2.0`). Explicit RSS tags bypass the threshold.
- `php artisan news:import-live-tech --limit=10 --per-feed=3` remains a separate bounded importer for the five verified public technology feeds. It ensures a target number of ready articles and stops at `--per-feed`; newly created source rows are stored **inactive** until an operator activates them.
- `rss:import` is no longer an alias of `news:import-live-tech`; the two commands serve different ingestion paths.
- No AI provider, rendering, upload, or publishing call is made by either importer. The pre-existing `news` row and all automation/publishing tables were preserved.

### 6.8 Manual G-02 triage command

- `php artisan ai-pvg:triage` exposes the deterministic G-02 pipeline to operators with the required `--run`, `--cluster`, and `--dry-run` flags.
- Scoring targets `status=ready` news without a `news_scores` row. Clustering then evaluates every returned story cluster through auto-greenlight and reports the action.
- The command checks `TriageGate` before any work and fails cleanly when the config switch or `triage.news_scoring_enabled` feature flag is disabled.
- `Http::preventStrayRequests()` blocks outbound HTTP for the command; scoring and clustering remain local TF-IDF only.
- `--dry-run` wraps the entire run in a transaction, forces planned greenlight output, and rolls back scores, clusters, automation runs, approval gates, and scripts.
- A dry run against the current development database scored 11 articles and made zero persistent changes (`news_scores=0`, `story_clusters=0`, `automation_runs=0`, `video_scripts=0`).
- Usage is documented in `OPERATOR_RUNBOOK.md` §5.5 and the command quick reference.

### 6.9 RSS ingestion → G-02 handshake

- Root cause of the “No unscored ready articles found” report was an import lifecycle mismatch: queued `ImportRssSourceJob` runs stored items as `draft`, while triage only scores `status=ready`. The 11 pre-existing ready rows were already scored, so 71 fresh RSS rows were invisible to G-02.
- Queued imports now pass `status=ready` to `RssImportService`. Direct service callers still default to `draft` unless they opt in.
- After a source imports at least one new item, `ImportRssSourceJob` queues `RunTriageJob` when the triage gate is enabled. The job is unique, delayed to coalesce parallel feed imports, rechecks the gate, and performs no HTTP calls.
- `TriageRunner` is shared by `ai-pvg:triage` and the queued handshake, so scoring, clustering, diagnostics, dry-run behavior, and auto-greenlight rules cannot drift.
- Story clustering now uses a 0.35 TF-IDF cosine threshold instead of 0.82. Calibration showed paraphrased same-event coverage scoring 0.39–0.59 while unrelated coverage stayed below 0.22; the threshold matches the scoring coverage threshold.
- When no cluster forms, the command reports candidate count, threshold, qualifying pairs, and the closest pair instead of lowering the threshold into false positives.
- The 71 legacy draft imports in the development database were promoted once; `ai-pvg:triage --run --cluster` then scored all 71 (82 total scores), found 51 recent candidates, and correctly reported zero 3-5 source clusters because no pair reached 0.35 (closest pair 0.21). The command created no auto-greenlight run or script for unrelated coverage.
- Feature coverage: `RssTriagePipelineTest` proves imported RSS items become ready, are scored, clustered, and reach planned auto-greenlight; disabled gates suppress both the queued handshake and the job; `StoryClusteringServiceTest` proves paraphrased multi-source coverage clusters while unrelated articles do not.

### 6.10 Unrestricted cross-category triage

- Scoring and clustering apply no category filter. `NewsScoringService` scores every ready article without a score, and `StoryClusteringService` evaluates the full recent candidate window.
- `RssTriagePipelineTest` imports AI, Security, and Startups tagged coverage from three sources, then proves all three categories are scored and grouped into one cross-category story cluster before planned auto-greenlight.
- `RssImportCommandTest` proves the broad command imports every item from every active source, is idempotent, and only truncates when explicit caps are supplied.
- `NewsCategoryDiscoveryTest` proves existing-category reuse, alias mapping, unknown-category creation, content detection, feed-level fallback, specific-over-generic tag priority, and that no article is skipped for lacking a category.
- Dev verification: `php artisan ai-pvg:triage --run --cluster --dry-run` evaluated 51 recent candidates across the existing categories and reported `0` pairs at the `0.35` threshold. It did not force a cluster for unrelated coverage and rolled back every write.

---

## 7. Development Database Preservation

Verified after the dynamic-category enhancement and final test runs:

```text
news=83 (seed row + local RSS imports)
news_sources=6 (all active: Laravel News + 5 configured live feeds)
news_categories=3 (Uncategorized, PHP, Technology)
rss_import_logs=21
video_renders=0
youtube_publishing_states=0
automation_runs=1
automation_stage_runs=0
ai_usage_ledger=0
budget_periods=1
approval_gates=0
provider_health_events=0
feature_flags=0
news_scores=83
story_clusters=0
story_cluster_news=0
script_variants=0
script_fact_checks=0
caption_tracks=0
audio_assets=0
visual_assets=0
scene_assets=0
render_variants=0
thumbnail_variants=0
metadata_variants=0
publishing_approval_sessions=0
co_pilot_review_sessions=0
```

Safety attestations:

- No `migrate:fresh`, `db:wipe`, truncation, or seeder was run against the development database.
- Only additive migrations and additive import rows were applied.
- The seed `news` row (ID 1) was left untouched; the remaining rows are local RSS imports.
- Tests use isolated `:memory:` SQLite.
- The `ai-pvg:triage --run --cluster --dry-run` verification rolled back all triage writes; `story_clusters` and `story_cluster_news` remain empty.

---

## 8. Roadmap Closure

**The authorized hyper-automation MVP (G-00 through G-06 plus Phase H) is DONE.** The delivered scope covers:

- G-00 verified local media baseline;
- G-01 control plane;
- G-02 triage;
- G-03 script agents;
- G-04 captions/audio;
- G-05 visual assets;
- G-06 format fan-out and metadata variants;
- Phase H publishing guards, Co-Pilot review, autopilot gates, read-only UI, and diagnostics.

**MVP Production-Ready Blueprint Complete** means the blueprint and its authorized implementation are complete and verified at the mocked/tested boundary. It does not claim live provider or live YouTube integration.

Post-MVP ideas remain optional and unauthorized, including autonomous QA/self-healing (original G-07), broader HITL/ops automation (original G-08), analytics feedback loops (H-01), experimentation deployment (H-02), optimization/adoption automation (H-04), scale governance (H-05), and compliance workflows (H-06). No additional feature was started.

---

## 9. Exact Final Verification Evidence

### 9.1 Full test suite

```text
Tests:    269 passed (2265 assertions)
Duration: 40.09s
0 failures, 0 risky
```

### 9.2 Dry-run walkthrough

```text
PASS  Tests\Feature\EndToEndAutomationDryRunWalkthroughTest
Tests:    1 passed (864 assertions)
Duration: 1.76s
0 failures, 0 risky
Http::assertNothingSent(); paid spend = 0
```

### 9.3 Real local MP4 verification

```text
Chrome: ...\Google\Chrome\Application\chrome.exe
FFprobe: remotion\node_modules\@remotion\compositor-win32-x64-msvc\ffprobe.exe
FFmpeg:  remotion\node_modules\@remotion\compositor-win32-x64-msvc\ffmpeg.exe
Remotion CLI: remotion\dist\render-cli.js
Payload: remotion\payload.json

File size: 35384 bytes
Video stream: YES; Audio stream: NO
Codec: h264; Resolution: 1080x1920; Frame rate: 30 fps
Duration: 6 seconds; Frames: 180
MP4 VERIFIED: storage\app\video_out\verify_20261025_174444.mp4
```

### 9.4 Phase H diagnostics

```text
Publishing / Co-Pilot enabled: NO (flag=publishing.enabled; gate=CLOSED; dual_public_publish=ENFORCED)
Publishing / Co-Pilot tables: 2/2 OK
Publishing / Co-Pilot data: approval_sessions=0; pending=0; approved=0; co_pilot_sessions=0
Autopilot gate: BLOCKED; blocked=g01_automation,g02_triage,g03_script_agents,g04_captions,g05_assets,g06_formats; budget_remaining_micros=5000000; breaker=closed
```

### 9.5 Walkthrough and runbook files

Created:

- `tests/Feature/EndToEndAutomationDryRunWalkthroughTest.php`
- `OPERATOR_RUNBOOK.md`

Modified:

- `app/Services/Automation/ScenePropBuilder.php`
- `app/Services/Automation/RemotionPropInjector.php`
- `remotion/src/VideoScript.tsx`
- `remotion/dist/VideoScript.js`
- `PROJECT_STATUS.md`

### 9.6 Phase H files

Created:

- `app/Exceptions/Automation/PublishingDisabledException.php`
- `app/Filament/Resources/PublishingStates/*`
- `app/Filament/Resources/CoPilotSessions/*`
- `app/Services/Publishing/PublishingGate.php`

Modified:

- `app/Services/Publishing/YouTubePublishService.php`
- `app/Services/Publishing/CoPilotReviewService.php`
- `app/Services/Automation/AutopilotGateService.php`
- `app/Models/PublishingApprovalSession.php`
- `app/Models/CoPilotReviewSession.php`
- `app/Console/Commands/AiPvgDiagnosticsCommand.php`
- `app/Livewire/StudioDashboard.php`
- `resources/views/studio/dashboard.blade.php`
- `config/automation.php`
- `.env.example`
- `tests/Feature/PhaseHPublishingTest.php`
- `PROJECT_STATUS.md`, `PROJECT_ROADMAP.md`, `AUTOMATION_EXPANSION_PROPOSAL.md`

Phase H migrations were already present at `fdf950c`:

- `database/migrations/2026_10_24_180000_create_publishing_approval_sessions_table.php`
- `database/migrations/2026_10_24_180100_create_co_pilot_review_sessions_table.php`

---

## 10. Remaining External Verification Boundary

The following are intentionally outside the tested Phase H implementation and are **not claimed as live-verified**:

1. Real YouTube OAuth handshake and real resumable upload.
2. Paid AI/TTS provider integration and provider-specific failure recovery.
3. Real production queue workers at load.
4. Redis/Horizon deployment and production SLO monitoring.
5. Real analytics ingestion, experimentation deployment, and compliance/legal workflows.

These boundaries are documented so the completed MVP is not misrepresented as a live-publishing system.
