<?php

use App\Services\Rendering\HyperFramesCompositionBuilder;

/**
 * Build HTML composition from the validated edit plan, with audio mix + captions.
 * Usage: php build_composition_from_plan.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Paths
$planPath = __DIR__.'/resources/hyperframes/samples/professional_24s.json';
$workDir = storage_path('app/hyperframes/sample_run_24s');
$mixFile = $workDir.'/mix.wav';
$transcriptFile = $workDir.'/transcript.json';

// Load plan
$plan = json_decode(file_get_contents($planPath), true);
if ($plan === null) {
    exit("ERROR: Cannot load plan from $planPath\n");
}

// Load transcript
$transcript = [];
if (is_file($transcriptFile)) {
    $transcript = json_decode(file_get_contents($transcriptFile), true);
}

// Find most recent mix file (pipeline run)
$mixFile = $workDir.'/mix.wav';
if (! is_file($mixFile)) {
    exit("ERROR: mix.wav not found in $workDir. Run run_audio_pipeline.php first.\n");
}

// Build composition
$builder = new HyperFramesCompositionBuilder;
$result = $builder->buildFromEditPlan($plan, [
    'run_id' => 'plan-24s-test',
    'audio_mix' => $mixFile,
    'word_timings' => isset($transcript['words']) ? $transcript['words'] : [],
    'captions_verified' => isset($transcript['words']) && count($transcript['words']) > 0,
    'tts' => [
        'provider' => 'windows-sapi',
        'voice' => 'Microsoft Zira Desktop',
    ],
    'transcription' => [
        'ok' => isset($transcript['words']) && count($transcript['words']) > 0,
        'words' => isset($transcript['words']) ? $transcript['words'] : [],
    ],
]);

echo "Composition built: {$result['projectDir']}\n";
echo "HTML: {$result['html']}bytes\n";
echo "Manifest: {$result['projectDir']}/manifest.json\n";
echo "Run ID: {$result['runId']}\n";
echo "Composition ID: {$result['compositionId']}\n";
