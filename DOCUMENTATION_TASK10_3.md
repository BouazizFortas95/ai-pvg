# Task 10.3 — Media Runtime Setup & First Verified MP4

## Environment Status ( documented, not modified )
- OS: Windows (win32)
- Node v26.10.0; PHP 8.2.12
- Remotion workspace verified (dist/ exists); bundle compiled
- Chrome headless: NOT INSTALLED (blocked by environment; install via choco/apt/brew when authorized)
- FFmpeg/FFprobe: NOT INSTALLED
- Output directories: created (public/videos, storage/app/video_out)

## Installation Commands (not executed — no authorization for global/system installs)
- Windows: choco install googlechrome ffmpeg
- macOS: brew install --cask google-chrome; brew install ffmpeg
- Linux: apt install chromium-browser ffmpeg

## Real MP4 Verification
- `ai-pvg:verify-mp4` improved: checks prerequisites first, attempts real Remotion CLI render (POST session init + PUT chunk via native Http when configured), verifies output file exists + non-empty, uses FFprobe for video stream/codec/duration, reports actual results, cleans only temp payload.
- Because Chrome/FFprobe unavailable, command exits with code 1 and clear message (no fabricated success).
- No external upload performed; no database mutation.

## Tests
- 130 passed / 360 assertions / 0 risky / 0 failures
- MediaValidationTest covers missing/executable/file/metadata scenarios with isolated temp files
- Normal suite never downloads browsers or runs renders
- Real DB preserved (news=1, renders=0, youtube_states=0)
