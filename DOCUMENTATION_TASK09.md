# AI-PVG Task 09 — End-to-End Pipeline Readiness

## Workflow
1. News → Script (approved) → Voice (optional/configured) → Render (Remotion CLI) → Validate (file + optional FFprobe) → Export (Task 08 preset + metadata) → Ready.

## Status Transitions
- Script: draft → approved
- Voice: pending → generating → completed/failed
- Render: pending → rendering → completed/failed (verified by output file size; FFprobe when available)
- Export: pending → completed/failed (validated by source render + preset + file check)

## Diagnostics
`php artisan ai-pvg:diagnostics` — read-only; checks migrations, queue, Remotion workspace, output permissions, FFprobe, providers.

## Real MP4 Verification
- Remotion CLI executes via `remotion/dist/render-cli.js`; Chrome headless downloaded automatically.
- Successful render produces `public/videos/render_{id}.mp4` when CLI exits 0.
- FFprobe unavailable in this environment (no `ffprobe` binary); validation reports `verified=false`, distinguishes basic file checks from verified metadata.
- To fully verify: install FFmpeg/FFprobe, then run render and `ffprobe -v error -show_entries stream=codec_name,width,height,duration -of csv...` on output.

## Security / Reliability
- No automatic external publishing (scope restriction).
- Export prevents access to unverified/uncompleted renders.
- Queue retries safe via idempotent checks; duplicate prevention in GenerateVoiceJob (ShouldBeUnique).
- Diagnostics never trigger renders or API calls.

## Tests
- 115 passed / 344 assertions at commit d627c21 (Task 08); 1 minor factory-related failure in VideoExportTaskTest (pre-existing, unrelated to core pipeline).
- Task 09 added: EndToEndPipelineTest + fixed VideoRenderStatus import.

## Known Limitations
- Real fully-playable MP4 depends on Remotion serve bundle + Chrome headless (verified CLI runs; full play test requires standard Remotion environment setup).
- FFprobe unavailable — media metadata reported unverified.
