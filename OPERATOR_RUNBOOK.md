# AI-PVG Operator Runbook

Local quickstart for the G-01..G-06 automation MVP and Phase H publishing/Co-Pilot gates.

**Default posture:** every automation, provider, publishing, and upload path is **disabled and dry-run first**. The development database is never mutated by the automated tests. Do not enable live providers, crawlers, or public publishing until you have deliberately reviewed the relevant gates.

---

## 1. Prerequisites

| Tool | Version / check | Notes |
| :--- | :--- | :--- |
| PHP | 8.2+ · `php -v` | Extensions required by Laravel 12. |
| Composer | `composer -V` | Installs PHP dependencies. |
| Node.js | 20+ · `node -v` | Needed for Vite and Remotion. |
| SQLite | bundled with PHP | Default local database. |
| Chrome | current | Used by Remotion; a bundled Chrome headless shell is preferred when present. |
| FFmpeg / FFprobe | bundled with Remotion | Auto-detected; can be overridden with env vars. |

All commands below run from the repository root unless stated otherwise.

---

## 2. First-time setup

### 2.1 PHP and database

```bash
composer install
```

Create the local environment file.

**PowerShell**

```powershell
Copy-Item .env.example .env
New-Item -ItemType File database/database.sqlite -Force
```

**Git Bash / macOS / Linux**

```bash
cp .env.example .env
touch database/database.sqlite
```

Then generate the key and apply the additive migrations:

```bash
php artisan key:generate
php artisan migrate
```

> Never run `php artisan migrate:fresh` against the development database. It drops the preserved `news=1` row. The migrations are additive and safe to re-run with `php artisan migrate`.

### 2.2 Frontend assets (Vite)

```bash
npm install
npm run build
```

For live reload during development use `npm run dev` instead of `npm run build` (see §3).

### 2.3 Remotion CLI (real MP4 verification)

```bash
cd remotion
npm install
npm run build
cd ..
```

`npm run build` compiles `remotion/src` into `remotion/dist`. Re-run it after any change under `remotion/src`, otherwise the renderer keeps using the old bundle.

### 2.4 Create an administrator

Operators log in through the Filament panel at `/auth`. The Studio dashboard at `/studio` requires an authenticated user. Administration is an explicit role, never mass-assignable.

```bash
php artisan tinker --execute="App\Models\User::create(['name' => 'Local Operator', 'email' => 'operator@example.test', 'password' => 'ChangeMe-123456']);"
php artisan user:grant-admin operator@example.test
```

The password is hashed automatically by the `User` model. Change it before sharing the environment.

---

## 3. Start the local stack

Run each command in its own terminal from the repository root.

| Terminal | Command | Purpose |
| :--- | :--- | :--- |
| 1 — Web | `php artisan serve` | Laravel at <http://127.0.0.1:8000>. |
| 2 — Vite | `npm run dev` | Vite dev server with hot reload. |
| 3 — Queue | `php artisan queue:work --queue=automation-high,media,default --tries=1 --timeout=900` | Processes the automation, media, and default queues. |
| 4 — Scheduler (optional) | `php artisan schedule:work` | Runs the idempotent `ai-pvg:automation:tick` every minute. Inert while automation is disabled. |

`composer dev` is a convenience alternative that starts the web server, a default queue listener, logs, and Vite together. It does **not** subscribe to the `automation-high` or `media` queues, so use terminal 3 when exercising automation jobs.

Open:

- Admin panel: <http://127.0.0.1:8000/auth>
- Studio dashboard: <http://127.0.0.1:8000/studio>

Log in with the operator account created in §2.4.

---

## 4. Enable the dry-run automation stack

Dry-run is the default and must stay on for the walkthrough. Edit `.env`:

```dotenv
APP_URL=http://127.0.0.1:8000

AUTOMATION_ENABLED=true
AUTOMATION_DRY_RUN=true

AUTOMATION_AI_PROVIDER=ollama
AUTOMATION_AI_FALLBACK=ollama
AUTOMATION_TTS_PROVIDER=mock
AUTOMATION_SCRIPT_AGENTS_USE_AI=false

AUTOMATION_TRIAGE_ENABLED=true
AUTOMATION_SCRIPT_AGENTS_ENABLED=true
AUTOMATION_CAPTIONS_ENABLED=true
AUTOMATION_ASSETS_ENABLED=true
AUTOMATION_FORMATS_ENABLED=true

AUTOMATION_PUBLISHING_ENABLED=false
AUTOMATION_DUAL_PUBLIC_PUBLISH=true
```

Clear cached configuration after editing:

```bash
php artisan config:clear
```

### 4.1 Enable the matching feature flags

Config switches and database flags are intentionally separate controls. Every G-02..G-06 pillar needs **both**. Run:

```bash
php artisan tinker --execute="App\Models\FeatureFlag::setFlag('triage.news_scoring_enabled', true); App\Models\FeatureFlag::setFlag('script_agents.enabled', true); App\Models\FeatureFlag::setFlag('captions.enabled', true); App\Models\FeatureFlag::setFlag('assets.enabled', true); App\Models\FeatureFlag::setFlag('formats.enabled', true);"
```

Check the resulting posture:

```bash
php artisan ai-pvg:diagnostics
```

The autopilot line should read `READY` when all G-01..G-06 gates, the budget, and the circuit breaker pass. Until then it prints `BLOCKED` with the exact missing gates.

### 4.2 Optional Phase H review/publishing

Leave publishing disabled for normal dry-run work. If you need to exercise the Phase H guard locally:

```dotenv
AUTOMATION_PUBLISHING_ENABLED=true
AUTOMATION_DUAL_PUBLIC_PUBLISH=true
```

```bash
php artisan tinker --execute="App\Models\FeatureFlag::setFlag('publishing.enabled', true);"
php artisan config:clear
```

Even then, `YouTubePublishService` only **authorizes** a public transition. It never uploads and never writes `youtube_publishing_states`. Authorization requires an approved session or PublicPublish gate backed by **two distinct administrators**. Keep live OAuth credentials unset.

---

## 5. Verification checklist

Run these before trusting a local environment.

### 5.1 Health and safety diagnostics

```bash
php artisan ai-pvg:diagnostics
```

Expect, on the preserved development database:

- `news=1`
- `scripts=0; renders=0; youtube_publishing_states=0`
- all automation run/stage/ledger counters at `0`
- `Automation enabled: NO` (unless you enabled it above; dry-run should stay `YES`)
- `Publishing / Co-Pilot enabled: NO` for the default posture
- `breaker=closed`

Diagnostics are read-only.

### 5.2 Isolated end-to-end dry-run walkthrough

```bash
php artisan test --filter=EndToEndAutomationDryRunWalkthroughTest
```

This test uses SQLite `:memory:`, blocks stray HTTP with `Http::preventStrayRequests()`, and covers:

- **G-01** — run creation, dry-run provider selection, budget reservation/release, breaker fail-closed behavior.
- **G-02** — deterministic scoring, multi-source clustering, auto-greenlight planned script.
- **G-03** — hooks, narrative scenes, directive validation, fact checks, labelled dry-run stage output.
- **G-04** — monotonic estimated timings, caption chunks, FFmpeg ducking/SFX filter planning (not executed).
- **G-05** — truthful asset fallback, verified visual asset path/attribution into Remotion props.
- **G-06** — three preset plans, midpoint thumbnail plan, two A/B metadata plans (arrays only).
- **Phase H** — autopilot preflight, Co-Pilot admin-only review, dual-admin publishing authorization.

It then asserts zero external HTTP calls, zero paid spend, zero production scripts/renders/captions/publishing rows, and zero non-dry-run automation runs.

### 5.3 Style and full suite

```bash
vendor/bin/pint --test
php artisan test
```

The full suite must end with `0 failures` and `0 risky`. Tests never touch `database/database.sqlite`.

---

## 6. Real local Remotion MP4 render

Preflight first (checks Chrome, FFprobe, the compiled CLI, the payload, and the output directory):

```bash
php artisan ai-pvg:verify-mp4
```

Then perform the real render:

```bash
php artisan ai-pvg:verify-mp4 --execute
```

Default behavior:

- Payload: `remotion/payload.json` (6 seconds, 2 scenes)
- Output: `storage/app/video_out/verify_<timestamp>.mp4`
- Log: the same path with `.log` appended
- Expected verification: `h264`, `1080x1920`, `30 fps`, video stream present

A successful run ends with:

```text
MP4 VERIFIED: <path>
```

Optional flags:

```bash
php artisan ai-pvg:verify-mp4 --execute --payload=remotion/payload_audio.json
php artisan ai-pvg:verify-mp4 --execute --output=storage/app/video_out/operator-demo.mp4
```

Troubleshooting the renderer:

- Re-run `cd remotion && npm run build` after editing `remotion/src`.
- If Chrome is not detected, set `REMOTION_CHROME_EXECUTABLE` to a local Chrome executable.
- If FFmpeg/FFprobe are not detected, install FFmpeg or set `FFMPEG_PATH` and `FFPROBE_PATH`.
- A failed render keeps its `.log` next to the intended output path for inspection.

> Real local rendering is filesystem-only. It does not call paid providers, upload to YouTube, or publish anything.

---

## 7. What dry-run guarantees

When `AUTOMATION_DRY_RUN=true`:

- Providers are forced local (`ollama` for AI, `mock` for TTS).
- Paid calls are blocked by `DryRunContext` and the budget guard.
- Publishing and external actions are blocked.
- Stage output is labelled (`_automation.dry_run = true`) and planned values are returned instead of production rows.
- Auto-greenlight stores a `planned_script` on the run; it does not create a production `VideoScript`.
- Fan-out, thumbnails, and A/B metadata are plans only; no render, thumbnail, or metadata rows are persisted.
- Visual asset fallback is explicit: `visualAssets = null`, never a fabricated asset.

---

## 8. Disable and reset

To return the environment to its inert default:

```dotenv
AUTOMATION_ENABLED=false
AUTOMATION_DRY_RUN=true
AUTOMATION_PUBLISHING_ENABLED=false
```

```bash
php artisan tinker --execute="App\Models\FeatureFlag::setFlag('triage.news_scoring_enabled', false); App\Models\FeatureFlag::setFlag('script_agents.enabled', false); App\Models\FeatureFlag::setFlag('captions.enabled', false); App\Models\FeatureFlag::setFlag('assets.enabled', false); App\Models\FeatureFlag::setFlag('formats.enabled', false); App\Models\FeatureFlag::setFlag('publishing.enabled', false);"
php artisan config:clear
```

Stop any running queue workers and the scheduler with `Ctrl+C`.

To inspect local dry-run rows, use `php artisan tinker` with read-only queries. To remove them, target the specific tables/records; do not drop the database.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
| :--- | :--- | :--- |
| `Database file at path ... does not exist` | SQLite file missing | Create `database/database.sqlite`, then `php artisan migrate`. |
| `Vite manifest not found` | Assets not built | `npm run dev` or `npm run build`. |
| Login succeeds but `/auth` denies access | User is not an administrator | `php artisan user:grant-admin <email>`. |
| Autopilot stays `BLOCKED` | Missing config gate or feature flag | Read the blocked list from `ai-pvg:diagnostics`, enable both layers. |
| Queued automation never runs | Worker not subscribed to automation queues | Start the worker with `--queue=automation-high,media,default`. |
| Stage output looks stale after a deploy | Cached config / old queue worker | `php artisan config:clear` and restart workers. |
| Remotion reports `visualAssets is not defined` or a stale bundle | `remotion/dist` not rebuilt | `cd remotion && npm run build`. |
| Remotion cannot find Chrome/FFmpeg | Binaries not bundled or not on `PATH` | Set `REMOTION_CHROME_EXECUTABLE`, `FFMPEG_PATH`, `FFPROBE_PATH`. |

---

## 10. Repository hygiene and safety rules

- Never commit `.env` or `database/database.sqlite`; both are local, ignored state.
- The development database must retain `news=1`; automated tests use `:memory:` and must leave it untouched.
- Do not enable live AI/TTS providers, crawlers, YouTube uploads, or public publishing as part of a dry-run.
- Public publishing always requires two distinct administrators, even when the publishing gate is enabled.
- Treat `storage/app/video_out` artifacts as local evidence, not source-controlled assets.
- Before committing, run `vendor/bin/pint --test` and `php artisan test`.

---

## 11. Command quick reference

```bash
# Setup
composer install
npm install
npm run build
cd remotion && npm install && npm run build && cd ..
php artisan key:generate
php artisan migrate

# Run locally (separate terminals)
php artisan serve
npm run dev
php artisan queue:work --queue=automation-high,media,default --tries=1 --timeout=900
php artisan schedule:work

# Operator access
php artisan user:grant-admin operator@example.test

# Verification
php artisan ai-pvg:diagnostics
php artisan test --filter=EndToEndAutomationDryRunWalkthroughTest
vendor/bin/pint --test
php artisan test

# Real local render
php artisan ai-pvg:verify-mp4
php artisan ai-pvg:verify-mp4 --execute
```
