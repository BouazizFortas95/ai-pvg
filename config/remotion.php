<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Remotion Runtime Paths
    |--------------------------------------------------------------------------
    |
    | Resolved by App\Support\RemotionRuntime. Leave null to auto-detect the
    | bundled Chrome/FFmpeg/FFprobe binaries shipped with Remotion.
    |
    */
    'node_binary' => env('REMOTION_NODE_BINARY', 'node'),
    'directory' => env('REMOTION_DIR'),
    'cli' => env('REMOTION_CLI'),
    'entry_point' => env('REMOTION_ENTRY_POINT'),

    'chrome_executable' => env('REMOTION_CHROME_EXECUTABLE'),
    'ffmpeg_path' => env('FFMPEG_PATH'),
    'ffprobe_path' => env('FFPROBE_PATH'),
    'port' => (int) env('REMOTION_PORT', 3099),
    'output_dir' => env('REMOTION_OUTPUT_DIR', 'public/videos'),

    /*
    |--------------------------------------------------------------------------
    | Verification Expectations
    |--------------------------------------------------------------------------
    */
    'timeout' => (int) env('REMOTION_RENDER_TIMEOUT', 600),
    'require_verification' => (bool) env('REMOTION_REQUIRE_VERIFICATION', true),
    'expected_codec' => env('REMOTION_EXPECTED_CODEC', 'h264'),
    'expected_width' => (int) env('REMOTION_EXPECTED_WIDTH', 1080),
    'expected_height' => (int) env('REMOTION_EXPECTED_HEIGHT', 1920),
    'expected_fps' => (float) env('REMOTION_EXPECTED_FPS', 30),
];
