<?php

namespace App\Console\Commands;

use App\Enums\VideoScriptStatus;
use App\Exceptions\Rendering\HyperFramesException;
use App\Models\VideoScript;
use App\Services\Media\MediaProbeService;
use App\Services\Rendering\Audio\AudioMixer;
use App\Services\Rendering\Audio\AudioPipeline;
use App\Services\Rendering\Audio\AudioProbe;
use App\Services\Rendering\Audio\MusicBedGenerator;
use App\Services\Rendering\Audio\SfxGenerator;
use App\Services\Rendering\Audio\TranscriptionService;
use App\Services\Rendering\Audio\TtsGenerator;
use App\Services\Rendering\EditPlan\EditPlanBuilder;
use App\Services\Rendering\HyperFramesCompositionBuilder;
use App\Services\Rendering\HyperFramesQaService;
use App\Services\Rendering\HyperFramesRenderService;
use App\Services\Rendering\ProcessRunner;
use App\Support\HyperFramesRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class VerifyHyperframesCommand extends Command
{
    protected $signature = 'ai-pvg:verify-hyperframes
        {--script-id= : Approved script ID to build in template mode}
        {--payload=remotion/payload.json : Synthetic JSON payload when no script id is given}
        {--preset=9:16 : Format preset (9:16, 16:9, 1:1)}
        {--theme=cyber : Design token theme (cyber, breaking, minimal)}
        {--output= : MP4 output path (defaults to storage/app/video_out/hyperframes_<timestamp>.mp4)}
        {--execute : Run the real lint + render + FFprobe verification; without it only preflight runs}
        {--audio : Run real audio checks (TTS narration presence, mix loudness, silence gaps)}
        {--voice= : SAPI voice name for local narration synthesis (e.g. Microsoft Zira Desktop)}
        {--qa : Run full QA gate suite (ffprobe, loudness, silence, A/V sync, frames, determinism, edit score)}
        {--determinism : Run two-render determinism check before QA}
        }';

    protected $description = 'Opt-in real HyperFrames verification — preflight by default, --execute to render';

    public function handle(
        MediaProbeService $probe,
        HyperFramesCompositionBuilder $builder,
        HyperFramesRenderService $renderer,
        HyperFramesQaService $qa,
        AudioProbe $audioProbe,
    ): int {
        $this->warn('=== OPT-IN HYPERFRAMES MP4 VERIFICATION ===');

        $status = HyperFramesRuntime::status();
        $output = $this->option('output')
            ? $this->resolvePath((string) $this->option('output'))
            : storage_path('app/video_out/hyperframes_'.date('Ymd_His').'.mp4');
        $preset = (string) $this->option('preset');

        $this->line('Template dir: '.$status['directory']);
        $this->line('Composition root: '.$status['output']);
        $this->line('Node: '.($status['node'] ?? 'MISSING'));
        $this->line('HyperFrames CLI: '.($status['cli'] ?? 'MISSING'));
        $this->line('FFmpeg (full build): '.($status['ffmpeg'] ?? 'MISSING'));
        $this->line('FFprobe: '.($status['ffprobe'] ?? 'MISSING'));
        $this->line('Gate: '.($status['enabled'] ? 'ENABLED' : 'disabled').' (flag '.$status['flag'].'='.($status['flag_enabled'] ? 'on' : 'off').')');
        $this->line('Preset: '.$preset);
        $this->line('Output: '.$output);

        $missing = [];

        if ($status['node'] === null) {
            $missing[] = 'Node.js 22+ is required. Install it and re-run.';
        }
        if ($status['cli'] === null) {
            $missing[] = 'HyperFrames CLI missing. Run: cd hyperframes && npm install';
        }
        if ($status['ffmpeg'] === null) {
            $missing[] = 'Full FFmpeg build missing. HyperFrames needs filters such as setparams (Remotion\'s bundled FFmpeg is stripped).';
        } elseif (! HyperFramesRuntime::ffmpegSupportsSetparams()) {
            $missing[] = 'The detected FFmpeg lacks the setparams filter. Install a full FFmpeg 9+ build (e.g. Gyan WinGet) or set AUTOMATION_HYPERFRAMES_FFMPEG_PATH.';
        }
        if ($status['ffprobe'] === null) {
            $missing[] = 'FFprobe is missing. Set FFPROBE_PATH or install FFmpeg.';
        }
        if (! is_dir($status['output']) && ! File::makeDirectory($status['output'], 0755, true) && ! is_dir($status['output'])) {
            $missing[] = 'Composition output dir is not writable: '.$status['output'];
        }

        $outputDir = dirname($output);
        if (! is_dir($outputDir) && ! File::makeDirectory($outputDir, 0755, true) && ! is_dir($outputDir)) {
            $missing[] = 'Output directory is not writable: '.$outputDir;
        }

        if ($missing !== []) {
            foreach ($missing as $message) {
                $this->error($message);
            }

            return 1;
        }

        if (! $this->option('execute')) {
            $this->info('Preflight passed. Re-run with --execute to perform a real HyperFrames render and FFprobe verification.');

            return 0;
        }

        $options = [
            'preset' => $preset,
            'theme' => (string) $this->option('theme'),
            'fps' => (int) config('automation.render.hyperframes.fps', 30),
        ];

        try {
            $scriptId = $this->option('script-id');
            $payloadPath = $this->option('payload');
            $voice = (string) $this->option('voice');
            $runAudio = (bool) $this->option('audio');
            $runQa = (bool) $this->option('qa');
            $runDeterminism = (bool) $this->option('determinism');
            $samplePlan = null;

            if ($scriptId !== null && $scriptId !== '') {
                $script = VideoScript::query()->find((int) $scriptId);

                if ($script === null) {
                    $this->error('Script not found: '.$scriptId);

                    return 1;
                }
                if ($script->status !== VideoScriptStatus::Approved) {
                    $this->error('Script '.$scriptId.' is not approved (status: '.$script->status->value.').');

                    return 1;
                }

                $runId = 'verify-script-'.$script->getKey().'-'.str_replace(':', 'x', $preset);
                $built = $builder->buildFromScript($script, [...$options, 'run_id' => $runId]);
            } elseif ($payloadPath !== null && is_file($this->resolvePath($payloadPath)) && preg_match('/\.json$/', $payloadPath)) {
                // Standard payload mode
                $payloadPath = $this->resolvePath($payloadPath);
                $payload = json_decode((string) file_get_contents($payloadPath), true);

                if (! is_array($payload)) {
                    $this->error('Payload is not valid JSON: '.$payloadPath);

                    return 1;
                }

                $runId = 'verify-payload-'.substr(sha1((string) json_encode($payload)), 0, 12).'-'.str_replace(':', 'x', $preset);
                $built = $builder->buildFromPayload($payload, [...$options, 'run_id' => $runId]);
            } else {
                // Professional edit plan mode (24s sample)
                $samplePath = storage_path('app/hyperframes/samples/professional_24s.json');

                if (! is_file($samplePath)) {
                    $this->error('Professional sample edit plan not found at: '.$samplePath);

                    return 1;
                }

                $samplePlan = json_decode((string) file_get_contents($samplePath), true);
                $runId = 'verify-plan-'.substr(sha1((string) json_encode($samplePlan)), 0, 12).'-'.str_replace(':', 'x', $preset);

                // Run full audio pipeline before building composition
                $this->info('Running audio pipeline (TTS → transcribe → music/SFX → mix)...');

                $audioPipeline = new AudioPipeline(
                    new TtsGenerator(new ProcessRunner),
                    new TranscriptionService(new ProcessRunner),
                    new MusicBedGenerator(new ProcessRunner),
                    new SfxGenerator(new ProcessRunner),
                    new AudioMixer(new ProcessRunner, $audioProbe),
                    $audioProbe,
                );

                $workDir = HyperFramesRuntime::join(
                    HyperFramesRuntime::outputRoot(),
                    $runId
                );

                $audioResult = $audioPipeline->build($samplePlan, $workDir, $voice !== '' ? $voice : null);

                if (isset($audioResult['error'])) {
                    $this->error('Audio pipeline failed: '.($audioResult['error'] ?? 'Unknown error'));

                    return 1;
                }

                $this->info("Audio mix created: {$audioResult['mix']}");
                $this->info("Duration: {$audioResult['duration']}s");
                $this->info("TTS Provider: {$audioResult['tts_provider']}");
                $this->info("Voice: {$audioResult['voice']}");
                $this->info('Words transcribed: '.count($audioResult['word_timings']));
                $this->info("Loudness: I={$audioResult['loudness']['integrated_lufs']} LUFS, TP={$audioResult['loudness']['true_peak_dbtp']} dB");

                foreach ($audioResult['warn'] ?? [] as $warn) {
                    $this->warn($warn);
                }

                // Convert sample plan to full edit plan using the builder
                $editPlanBuilder = app(EditPlanBuilder::class);
                $editPlan = $editPlanBuilder->fromPayload($samplePlan, [
                    'preset' => (string) $options['preset'],
                    'theme' => (string) ($options['theme'] ?? 'cyber'),
                    'voice' => $voice !== '' ? $voice : 'auto',
                    'run_id' => $runId,
                ]);

                // Build composition with the audio mix and captions
                $built = $builder->buildFromEditPlan($editPlan, [
                    ...$options,
                    'run_id' => $runId,
                    'audio_mix' => $audioResult['mix'],
                    'word_timings' => $audioResult['word_timings'],
                    'captions_verified' => $audioResult['transcription']['ok'] ?? false,
                    'tts' => [
                        'provider' => $audioResult['tts_provider'],
                        'voice' => $audioResult['voice'],
                    ],
                    'transcription' => $audioResult['transcription'],
                ]);
            }

            $this->line('Composition: '.$built['projectDir']);
            $this->line('Run id: '.$built['runId']);
            $this->info('Starting HyperFrames lint + render...');

            $result = $renderer->lintAndRender($built, $output);
        } catch (HyperFramesException $exception) {
            $this->error('HyperFrames '.$exception->stage.' failed: '.$exception->getMessage());

            return 1;
        } catch (\Throwable $exception) {
            $this->error('HyperFrames verification crashed: '.$exception->getMessage());

            return 1;
        }

        $metadata = $probe->probe($output);
        $manifest = $built['manifest'] ?? [];
        $check = $probe->matches($metadata, [
            'codec' => (string) config('remotion.expected_codec', 'h264'),
            'width' => (int) ($manifest['width'] ?? config('remotion.expected_width', 1080)),
            'height' => (int) ($manifest['height'] ?? config('remotion.expected_height', 1920)),
            'fps' => (float) ($manifest['fps'] ?? config('remotion.expected_fps', 30)),
            'min_duration' => 0.1,
            'require_audio' => ($manifest['audio'] ?? []) !== [],
        ]);

        $this->line('File size: '.filesize($output).' bytes');
        $this->line('FFprobe available: '.($metadata['available'] ? 'YES' : 'NO'));
        $this->line('Video stream: '.($metadata['video_stream'] ? 'YES' : 'NO'));
        $this->line('Audio stream: '.($metadata['audio_stream'] ? 'YES' : 'NO'));
        $this->line('Codec: '.($metadata['codec'] ?? 'none'));
        $this->line('Resolution: '.($metadata['width'] ?? '?').'x'.($metadata['height'] ?? '?'));
        $this->line('Frame rate: '.($metadata['fps'] ?? '?').' fps');
        $this->line('Duration: '.($metadata['duration'] ?? '?').' seconds');
        $this->line('Frames: '.($metadata['frames'] ?? '?'));

        if (! $check['valid']) {
            foreach ($check['errors'] as $error) {
                $this->error($error);
            }
            $this->error('HyperFrames MP4 verification FAILED. Output kept for inspection: '.$output);

            return 1;
        }

        // FAIL CLOSED: If narration was expected (audio_mix passed to build),
        // and the MP4 has no audio stream, this must be FAILED, never verified.
        $audioExpected = isset($built['audioClips']) && $built['audioClips'] !== [];
        $audioPresent = $metadata['audio_stream'] ?? false;

        if ($audioExpected && ! $audioPresent) {
            $this->error('FAIL CLOSED: Audio mix was produced (narration + music + SFX) but rendered MP4 has NO audio stream. Render FAILED. No audio = never verified.');
            $this->line('Evidence: audioClips '.count($built['audioClips']).'; audio_stream='.($audioPresent ? 'Y' : 'N').'; mix file '.($audioExpected ? 'expected' : 'not expected'));

            return 1;
        }

        // If --audio was set, confirm narration file exists (not mock) and loudness
        if ((bool) $this->option('audio')) {
            $this->line('=== AUDIO CHECK ===');
            $audioMix = $audioResult['mix'] ?? null;

            if ($audioMix !== null && is_file($audioMix)) {
                $probeResult = $audioProbe->loudness($audioMix);
                $this->line('Mix loudness: I='.$probeResult['integrated_lufs'].' LUFS, TP='.$probeResult['true_peak_dbtp'].' dB');
            } else {
                $this->warn('Audio mix file missing; narration not synthesized in real mode.');
            }
        }

        // QA gate suite
        if ((bool) $this->option('qa') || ((bool) $this->option('audio') && ! $audioExpected)) {
            $this->line('=== QA GATES ===');
            $qaResult = $qa->verify($built['projectDir'], $samplePlan ?? null, ['output_file' => $output]);

            foreach ($qaResult['gates'] as $gate) {
                $color = match ($gate['status']) {
                    'PASS' => 'info',
                    'WARN' => 'warn',
                    'FAIL' => 'error',
                    default => 'line',
                };

                $this->$color($gate['name'].': '.$gate['status'].' — '.$gate['detail']);
            }

            if (! $qaResult['passed']) {
                $this->error('QA GATES FAILED. Edit score: '.$qaResult['edit_score']);

                return 1;
            }

            $this->info('QA PASSED. Edit score: '.$qaResult['edit_score'].' / 100');
        }

        $this->info('HYPERFRAMES MP4 VERIFIED: '.$output);
        $this->line('Manifest: '.$built['projectDir'].'/manifest.json');
        $this->line('FFprobe command: '.(string) $metadata['ffprobe_path'].' -v error -show_format -show_streams '.$output);

        return 0;
    }

    private function resolvePath(string $path): string
    {
        return HyperFramesRuntime::isAbsolute($path) ? $path : base_path($path);
    }
}
