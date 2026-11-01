# AI-PVG: Autonomous AI Programmatic Video Generator

AI-PVG is an enterprise-grade autonomous pipeline studio and rendering system built on Laravel 13, Filament, HyperFrames, Remotion, and ElevenLabs. It transforms breaking news into multi-format, studio-grade vertical and landscape news videos through a 6-stage deterministic pipeline.

---

## Architecture Overview

```
                                      AI-PVG Pipeline Flow
 ┌───────────────┐     ┌────────────────┐     ┌───────────────┐
 │ 1. Ingest     │ ──> │ 2. Cluster     │ ──> │ 3. Script     │
 │ News Triage   │     │ & Greenlight   │     │ & Edit Plan   │
 └───────────────┘     └────────────────┘     └───────────────┘
                                                      │
 ┌───────────────┐     ┌────────────────┐     ┌───────┴───────┐
 │ 6. Render MP4 │ <── │ 5. Motion IP   │ <── │ 4. ElevenLabs │
 │ & Manifest    │     │ & Multi-Format │     │ Audio & Captions
 └───────────────┘     └────────────────┘     └───────────────┘
         │
         ▼
 🔒 ZERO PUBLISHING LOCK: All outputs saved to storage/app/video_out/
```

### 1. Motion-Graphics Library ("Prompt Motion")
- **Intellectual Property Taxonomy:**
  - `reference_only`: Usable as design inspiration; **strictly blocked** by the validator and preflight from being rendered into shipping video.
  - `cleared`: Third-party recipes/components with explicit usage clearance.
  - `original`: In-house original IP created by the team or compiled by LLM with certified unique code.
- **Contract & Validation:**
  - Zero external assets or network fetches; pauses GSAP timeline offline.
  - Deterministic Mulberry32 PRNG seed generation per video scene.
  - Contrast threshold $\ge 4.5:1$ strictly checked against background colors.
- **Admin Review:** Complete Filament Resource under **Motion Library** with real-time preview, code inspection, and approval actions.

### 2. ElevenLabs Studio Audio Pipeline
- **Timestamped TTS:** Word-level monotonic timings generated via `/v1/text-to-speech/{voice_id}/with-timestamps`.
- **Sound Effects (SFX):** Generated via `/v1/sound-generation` or procedural low-pass/bandpass noise synthesis when local fallback is active.
- **Instrumental Music:** Ambient beds generated via `/v1/music` or procedural melodic synthesis.
- **Global SHA-256 Cache:** Cached across runs under `storage/app/audio_library/{sfx,music,voices}/`.
- **Broadcast Loudness Compliance:** Enforces EBU R128 ($-14\text{ LUFS} \pm 1.0\text{ LUFS}$, True Peak $\le -1.0\text{ dBTP}$).
- **Budget Guard:** Real-time ledger accounting with reservation/settle lifecycle (`ai_usage_ledger`).

### 3. Production Data Map & Preflight Diagnostics
- **Preflight Diagnostics (`ProductionPreflight`):** Audits all upstream requirements across all 6 steps before rendering begins.
- **Per-Run Folder Layout:** Artifacts structured strictly under `storage/app/production/{run_id}/`:
  - `audio/`: Full voiceover narration, sound effects, background music.
  - `manifest/`: Canonical `production_manifest.json` schema v1.0.0.
  - `props/`: Remotion / HyperFrames visual scene properties.
- **Canonical Manifest (`production_manifests` table):** Tracks immutable run snapshots with SHA-256 integrity checksums.

### 4. Zero Publishing Safety Lock
- `automation.publishing.enabled = false` is enforced across all configs, migrations, pipeline runners, and preflight checks.
- Completed videos are verified locally with FFprobe and stored in `storage/app/video_out/`.

---

## Automated Test Verification

AI-PVG maintains 100% passing test coverage with zero stray HTTP requests (`Http::preventStrayRequests()`):

```bash
# Run all feature tests
php vendor/bin/phpunit tests/Feature/
```

Key test suites:
- `ProductionDataMapTest.php`: Drift prevention test ensuring all tables, columns, and artifacts match documentation.
- `ElevenLabsClientTest.php`: ElevenLabs API contract and error handling verification.
- `SoundEffectServiceTest.php` & `MusicServiceTest.php`: Audio generation and global SHA-256 caching.
- `MixAndLoudnessGateTest.php`: EBU R128 loudness gating and audio timeline integration.
- `MotionLibraryTest.php`: Prompt Motion import, IP licensing, and review status.
- `MotionCompilerAndSelectorTest.php`: Mulberry32 seed determinism, validator, and compiler.
- `ProductionPreflightAndManifestTest.php`: Preflight diagnostics and manifest SHA-256 verification.
- `EndToEndPipelineTest.php`: End-to-end 6-stage video production pipeline execution.

---

## License

Proprietary — Internal AI-PVG Studio Engine.
