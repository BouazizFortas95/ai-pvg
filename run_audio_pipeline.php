<?php

/**
 * Run the real audio pipeline: TTS → transcribe → music/SFX → mix.
 * Outputs: mix.wav, transcript.json, duration, word timings, loudness.
 *
 * Usage: php run_audio_pipeline.php
 */

require __DIR__.'/vendor/autoload.php';

// Bootstrap Laravel minimally
$app = require __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Resolve services
$runtime = new App\Support\HyperFramesRuntime;
$runner = new App\Services\Rendering\ProcessRunner;
$probe = new App\Services\Rendering\Audio\AudioProbe($runner);

// Paths
$planPath = __DIR__.'/resources/hyperframes/samples/professional_24s.json';
$workDir = storage_path('app/hyperframes/sample_run_24s');
$voice = 'Microsoft Zira Desktop';

// Load plan
$plan = json_decode(file_get_contents($planPath), true);
if ($plan === null) {
    die("ERROR: Cannot load plan from $planPath\n");
}

// Create work dir
if (! is_dir($workDir)) {
    mkdir($workDir, 0777, true);
}

// Initialize pipeline
$pipeline = new App\Services\Rendering\Audio\AudioPipeline(
    new App\Services\Rendering\Audio\TtsGenerator($runner, ''),
    new App\Services\Rendering\Audio\TranscriptionService($runner),
    new App\Services\Rendering\Audio\MusicBedGenerator($runner),
    new App\Services\Rendering\Audio\SfxGenerator($runner),
    new App\Services\Rendering\Audio\AudioMixer($runner, $probe),
    $probe
);

try {
    $result = $pipeline->build($plan, $workDir, $voice);

    // Output results
    echo "=== AUDIO PIPELINE RESULTS ===\n";
    echo "TTS Provider: {$result['tts_provider']}\n";
    echo "Voice: {$result['voice']}\n";
    echo "Duration: {$result['duration']}s\n";
    echo "Words transcribed: " . count($result['word_timings']) . "\n";
    echo "Mix file: {$result['mix']}\n";
    echo "Loudness: I={$result['loudness']['integrated_lufs']} LUFS, TP={$result['loudness']['true_peak_dbtp']} dB\n";

    if (isset($result['warn']) && is_array($result['warn'])) {
        foreach ($result['warn'] as $w) echo "WARN: $w\n";
    }

    // Handle transcription availability with ternary
    $transcriptionReason = $result['transcription']['ok'] ? '' : 
        ($result['transcription']['reason'] ?? 'unknown');
    echo "WARN: Transcription unavailable: $transcriptionReason\n";

    // Save transcript path for later use
    $transcriptPath = $result['transcription']['transcriptPath'] ?? null;
    if ($transcriptPath && is_file($transcriptPath)) {
        copy($transcriptPath, $workDir.'/transcript.json');
        echo "Transcript copied to: {$workDir}/transcript.json\n";
    }

    // Ensure audio mix file exists
    if (! is_file($result['mix'])) {
        throw new RuntimeException('Audio mix file missing: ' . $result['mix']);
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// Cleanup: keep work dir for next step; you can rm -rf it manually
echo "\nPipeline complete. Work dir: $workDir\n";
echo "Keep this dir; the next step needs:\n";
echo "  - mix.wav\n";
echo "  - transcript.json (word timings)\n";