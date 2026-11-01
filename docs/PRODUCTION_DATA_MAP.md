# AI-PVG Autonomous Pipeline Production Data Map

> **Specification & Runtime Verification Matrix**
> Single Source of Truth for all production stages, additive tables, storage paths, services, and artifacts.
> Verified against the live repository at `2026-10-31T22:05:00+01:00`.

---

## 1. Overview & Architecture Principles

1. **Additive Only**: Every new feature or schema upgrade extends existing foundations without breaking backward compatibility or dropping tables.
2. **Flag-Gated & Local by Default**: Publishing remains **100% LOCKED OFF** (`automation.publishing.enabled = false`). All third-party providers default to local offline mode.
3. **Canonical Audio Truth**: `audio_timeline.json` and `mix.wav` are the single source of truth for all scene durations, word timings, and loudness standards. Scene durations are derived from measured narration durations, never estimated.
4. **No Phantom Endpoints / No Invented APIs**: All third-party integrations (ElevenLabs) and external repositories (Prompt Motion) are verified against official API specifications and terms before any code is authored.
5. **Automated Verification**: This data map is continuously verified by `Tests\Feature\ProductionDataMapTest` to ensure that documentation, database schema, and runtime services never drift.

---

## 2. Production Stage Data Map

The table below maps every piece of data through the 6-stage autonomous media pipeline:

| Datum | Stage | Storage Location | Producer Service | Consumer Service(s) | Status |
|---|---|---|---|---|---|
| **Raw Articles** | 1. Ingest | Database: `news`, `news_sources`, `news_categories` | `RssImportService` / `SeedMockCluster` | `TriageRunner`, `TriageGate` | `VERIFIED` |
| **Article Scores** | 2. Triage | Database: `news_scores` | `TriageRunner` / `TriageGate` | `TriageRunner` | `VERIFIED` |
| **Story Clusters** | 2. Triage | Database: `story_clusters`, `story_cluster_news` | `TriageRunner` | `ScriptPipelineService` | `VERIFIED` |
| **Video Scripts** | 3. Script | Database: `video_scripts` | `ScriptPipelineService` | `EditPlanBuilder`, `PipelineStepService` | `VERIFIED` |
| **Script Variants & Hooks** | 3. Script | Database: `script_variants` | `ScriptPipelineService` | `VideoScript` selection, A/B Testing | `VERIFIED` |
| **Fact Checks** | 3. Script | Database: `script_fact_checks` | `ScriptPipelineService` | Approval Gate | `VERIFIED` |
| **Edit Decision Plan** | 3. Script | File: `edit_plan.json` | `EditPlanBuilder` | `EditPlanValidator`, `AudioPipeline`, `HyperFrames` | `VERIFIED` |
| **Emotion / Tone Tags** | 3. Script | In `edit_plan.json` (`directives.emotion_tone`) | `ScriptPipelineService` / `EditPlanBuilder` | `TtsGenerator` / `ElevenLabsClient`, `MotionSelector` | `VERIFIED` |
| **Local TTS Audio** | 4. Audio | Database: `audio_assets` (kind=`narration`) | `VoiceGenerationService` / `TtsGenerator` | `AudioMixer`, `VideoRenderingService` | `VERIFIED` |
| **ElevenLabs Voice Profile** | 4. Audio | Database: `voice_profiles` | `VoiceCatalog` / Filament UI | `ElevenLabsClient`, `TtsService` | `VERIFIED` |
| **ElevenLabs TTS Segments** | 4. Audio | File: `audio/narration/seg_###.wav` | `ElevenLabsClient` / `TtsService` | `AudioTimelineBuilder`, `AudioMixer` | `PLANNED` |
| **ElevenLabs Alignment Data** | 4. Audio | File: `audio/narration/alignment_###.json` | `ElevenLabsClient` (with-timestamps) | `AlignmentConverter`, `AudioTimelineBuilder` | `PLANNED` |
| **Word-Level Captions** | 4. Audio | Database: `caption_tracks`, File: `captions.json` | `TranscriptionService` / `CaptionTrackBuilder` | `HyperFramesCompositionBuilder`, Video player | `VERIFIED` |
| **SFX Bed & Library Cache** | 4. Audio | File: `storage/app/audio_library/sfx/{cue}.wav` | `SfxGenerator` / `SoundEffectService` | `AudioMixer`, `AudioTimelineBuilder` | `VERIFIED` |
| **Music Bed & Library Cache** | 4. Audio | File: `storage/app/audio_library/music/{mood}.wav` | `MusicBedGenerator` / `MusicService` | `AudioMixer`, `AudioTimelineBuilder` | `VERIFIED` |
| **Pre-Mixed Audio Track** | 4. Audio | File: `audio/mix.wav` (-14 LUFS, <= -1 dBTP) | `AudioMixer` / `MixService` | `HyperFramesRenderService`, Remotion mux | `VERIFIED` |
| **Canonical Audio Manifest** | 4. Audio | File: `audio/audio_timeline.json` | `AudioTimelineBuilder` | `AudioTimelineValidator`, `HyperFramesRenderService` | `VERIFIED` |
| **Visual B-Roll Assets** | 5. Visuals | Database: `visual_assets`, `scene_assets` | `ScenePropBuilder` / `AssetScraperService` | `HyperFramesCompositionBuilder`, Remotion | `VERIFIED` |
| **Motion Prompt Recipes** | 5. Visuals | Database: `motion_recipes`, `motion_sources` *(Additive)*| `MotionRecipeImporter` (Manual Curated Import) | `MotionComponentCompiler` | `VERIFIED` |
| **Compiled Motion Components** | 5. Visuals | Database: `motion_components`, Files: `resources/motion/` | `MotionComponentCompiler` | `MotionSelector`, `HyperFramesCompositionBuilder` | `VERIFIED` |
| **Motion Component Usages** | 5. Visuals | Database: `motion_component_usages` *(Additive)* | `MotionSelector` (Seeded PRNG) | `HyperFramesQaService`, Variation Fingerprint | `VERIFIED` |
| **Presenter / Avatar Spec** | 5. Visuals | Database: `avatar_characters`, `variation_history` | `AvatarQaService` / `AvatarPrng` | `HyperFramesCompositionBuilder` | `VERIFIED` |
| **Multi-Format Fan-Out Plans** | 5. Visuals | Database: `render_variants`, `metadata_variants` | `FanOutBatchService` | `HyperFramesRenderService`, Publishing staging | `VERIFIED` |
| **Preflight Readiness Check** | 6. Render | Diagnostics: `ProductionPreflight` | `ProductionPreflight` | Pipeline Runner Page, CLI | `PLANNED` |
| **HyperFrames Composition** | 6. Render | Files: `storage/app/hyperframes/{run_id}/index.html` | `HyperFramesCompositionBuilder` | `HyperFramesRenderService` | `VERIFIED` |
| **Rendered MP4 Video** | 6. Render | File: `storage/app/video_out/{run_id}.mp4` | `HyperFramesRenderService` / `RemotionRenderer` | In-browser player, QA gates | `VERIFIED` |
| **QA Verification & Scores** | 6. Render | Files: `qa/report.json`, Database: `video_renders` | `HyperFramesQaService` (7 Gates) | Quarantine Gate, Verified Badge | `VERIFIED` |
| **Production Run Manifest** | 6. Render | Database: `production_manifests`, File: `production_manifest.json` | `ProductionManifestBuilder` | Complete run audit & replayability | `VERIFIED` |

---

## 3. Storage Hierarchy & Layout

### A. Per-Run Isolated Output Directory (`storage/app/production/{run_id}/`)
Every pipeline execution writes self-contained, auditable artifacts to this structured root:
```text
storage/app/production/{run_id}/
├── manifest/
│   └── production_manifest.json          # Schema-validated run manifest with sha256 checksums
├── edit_plan.json                         # Validated edit decision list (timing, beats, scenes)
├── audio/
│   ├── narration/
│   │   ├── seg_001.wav                    # Measured 48kHz audio segment
│   │   ├── alignment_001.json             # Character/word alignment data from provider
│   │   └── ...
│   ├── audio_timeline.json                # Single audio source of truth
│   ├── mix.wav                            # Pre-mixed broadcast master (-14 LUFS, <= -1 dBTP)
│   └── stems/                             # Separated stems (voice.wav, music.wav, sfx.wav)
├── captions/
│   └── captions.json                      # Monotonic word timings and styled chunk metadata
├── composition/
│   ├── index.html                         # Self-contained HyperFrames HTML/CSS/GSAP bundle
│   ├── assets/                            # Local visual and motion assets for this run
│   └── vendor/                            # Vendored local offline JS libraries (GSAP, fonts)
├── out/
│   ├── 9x16.mp4                           # Primary vertical format (1080x1920)
│   └── ...                                # Optional fan-out formats (16x9.mp4, 1x1.mp4)
├── qa/
│   ├── report.json                        # 7-Gate QA scores, determinism hash, and loudness audit
│   ├── contact_sheet.png                  # Frame grid verification contact sheet
│   ├── waveform.png                       # Audio loudness & ducking waveform visual
│   └── loudness.json                      # EBU R128 integrated, range, and true peak metrics
└── logs/
    └── run.log                            # Full streamed process logs (TTS, FFmpeg, HyperFrames)
```

### B. Global Reusable Asset Library (`storage/app/audio_library/`)
Shared across runs to prevent redundant synthesis and avoid unnecessary API costs:
```text
storage/app/audio_library/
├── sfx/                                   # Cached sound effects
│   ├── whoosh_impact.wav
│   └── whoosh_impact.json                 # Sidecar metadata: prompt, model, duration, sha256
├── music/                                 # Cached background music beds
│   ├── cyber_pulse_60s.wav
│   └── cyber_pulse_60s.json               # Sidecar metadata: mood, bpm, duration, loudness
└── voices/                                # Cached speech segments keyed by sha256(text+voice+model)
    └── {hash}.wav
```

### C. Motion Component Library (`resources/motion/`)
Vendored, self-contained offline animation modules:
```text
resources/motion/
├── vendor/                                # Vendored GSAP and animation plugins (offline only)
├── tokens/                                # Typography, safe-zone grids, palette CSS variables
└── components/                            # Approved, versioned motion components
    ├── lower-thirds/
    ├── headline-bars/
    ├── tickers/
    ├── stat-counters/
    ├── quote-cards/
    ├── map-callouts/
    ├── icon-callouts/
    ├── kinetic-typography/
    ├── transitions/
    ├── animated-backgrounds/
    ├── stingers/
    └── disclosure-badges/
```

---

## 4. Additive Database Schema Specifications

The following additive tables are planned for Milestones Q2–Q6 to support Prompt Motion, ElevenLabs, and Production Manifests without modifying any existing production tables:

```sql
-- Motion Graphics Sources (Origins / Creators)
CREATE TABLE motion_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_url TEXT NOT NULL,
    creator_handle TEXT NOT NULL,
    site_name TEXT NOT NULL,
    license_status TEXT NOT NULL DEFAULT 'reference_only', -- reference_only | cleared | original
    attribution_text TEXT NOT NULL,
    notes TEXT NULL,
    created_at DATETIME,
    updated_at DATETIME
);

-- Motion Recipes (Imported Prompts & Skills)
CREATE TABLE motion_recipes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    motion_source_id INTEGER REFERENCES motion_sources(id),
    title TEXT NOT NULL,
    prompt_text TEXT NOT NULL,
    category TEXT NOT NULL, -- lower_third | ticker | kinetic_type | transition | ...
    source_payload JSON NULL,
    status TEXT NOT NULL DEFAULT 'imported', -- imported | authored | rejected
    license_status TEXT NOT NULL DEFAULT 'reference_only',
    created_at DATETIME,
    updated_at DATETIME
);

-- Compiled, Validated Motion Components
CREATE TABLE motion_components (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recipe_id INTEGER REFERENCES motion_recipes(id),
    component_key TEXT UNIQUE NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    category TEXT NOT NULL,
    html_template TEXT NOT NULL,
    gsap_script TEXT NOT NULL,
    params_schema JSON NOT NULL,
    supported_formats JSON NOT NULL, -- ["9:16", "16:9", "1:1"]
    sfx_hooks JSON NULL,
    tone_compatibility JSON NOT NULL, -- ["normal", "sensitive", "tragic"]
    contrast_rating REAL NOT NULL,
    license_status TEXT NOT NULL DEFAULT 'reference_only',
    status TEXT NOT NULL DEFAULT 'pending_review', -- pending_review | approved | rejected
    approved_at DATETIME NULL,
    approved_by INTEGER REFERENCES users(id),
    created_at DATETIME,
    updated_at DATETIME
);

-- Motion Component Usage Tracking per Render
CREATE TABLE motion_component_usages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pipeline_run_id INTEGER REFERENCES pipeline_runs(id),
    motion_component_id INTEGER REFERENCES motion_components(id),
    scene_index INTEGER NOT NULL,
    applied_params JSON NOT NULL,
    seed INTEGER NOT NULL,
    created_at DATETIME
);

-- Voice Profiles (ElevenLabs & Stock Voices)
CREATE TABLE voice_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider TEXT NOT NULL, -- local | elevenlabs
    voice_id TEXT NOT NULL,
    name TEXT NOT NULL,
    model_id TEXT NOT NULL,
    language TEXT NOT NULL DEFAULT 'en',
    settings JSON NULL,
    license_note TEXT NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT 1,
    created_at DATETIME,
    updated_at DATETIME
);

-- Complete Immutable Production Run Manifest
CREATE TABLE production_manifests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id TEXT UNIQUE NOT NULL,
    pipeline_run_id INTEGER REFERENCES pipeline_runs(id),
    video_script_id INTEGER REFERENCES video_scripts(id),
    status TEXT NOT NULL, -- staging | verified | quarantined
    manifest_data JSON NOT NULL,
    checksum_sha256 TEXT NOT NULL,
    created_at DATETIME,
    updated_at DATETIME
);
```

---

## 5. Machine-Readable Verification Registry

The structured data below is read directly by `Tests\Feature\ProductionDataMapTest` and `App\Services\Production\ProductionPreflight` to prevent documentation drift:

```yaml
data_map_registry:
  version: "1.0.0"
  verified_tables:
    - news
    - news_sources
    - news_categories
    - news_scores
    - story_clusters
    - story_cluster_news
    - video_scripts
    - script_variants
    - script_fact_checks
    - audio_assets
    - caption_tracks
    - voice_generations
    - visual_assets
    - scene_assets
    - video_renders
    - render_variants
    - video_exports
    - pipeline_runs
    - pipeline_run_stages
    - avatar_characters
    - variation_history
    - voice_profiles
    - motion_sources
    - motion_recipes
    - motion_components
    - motion_component_usages
    - production_manifests
    - ai_usage_ledger
    - budget_periods
    - provider_health_events
    - feature_flags

  verified_config_keys:
    - automation.enabled
    - automation.dry_run
    - automation.budget
    - automation.render.formats
    - automation.render.driver
    - automation.render.hyperframes.enabled
    - automation.render.hyperframes.directory
    - automation.render.hyperframes.output_video_dir
    - automation.render.hyperframes.audio.target_lufs
    - automation.render.hyperframes.audio.true_peak
    - automation.render.hyperframes.audio.max_silence_gap
    - automation.render.hyperframes.qa.loudness_target
    - automation.render.hyperframes.qa.sync_ms
    - automation.audio.provider
    - automation.publishing.enabled

  verified_base_paths:
    - storage/app/video_out
    - storage/app/hyperframes
    - remotion
    - hyperframes
```

