<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class VerifyMp4Command extends Command
{
    protected $signature = 'ai-pvg:verify-mp4 {--payload=remotion/payload_audio.json : JSON payload for synthetic render}';

    protected $description = 'Opt-in real MP4 verification — never auto-runs; requires Chrome + FFmpeg/FFprobe';

    public function handle(): int
    {
        $this->warn('=== OPT-IN REAL MP4 VERIFICATION ===');
        $chromePath = trim(env('REMOTION_CHROME_EXECUTABLE', ''));
        if ($chromePath && file_exists($chromePath)) $chrome = $chromePath;
        else $chrome = shell_exec('which google-chrome 2>/dev/null || which chromium 2>/dev/null || which chrome 2>/dev/null || echo NONE');
        $ffprobePath = trim(env('FFPROBE_PATH', ''));
        if ($ffprobePath && file_exists($ffprobePath)) $ffprobe = $ffprobePath;
        else $ffprobe = shell_exec('which ffprobe 2>/dev/null || echo NONE');
        $ffmpegPath = trim(env('FFMPEG_PATH', ''));
        if ($ffmpegPath && file_exists($ffmpegPath)) $ffmpeg = $ffmpegPath;
        else $ffmpeg = shell_exec('which ffmpeg 2>/dev/null || echo NONE');
        $this->line('Chrome: '.trim($chrome));
        $this->line('FFprobe: '.trim($ffprobe));
        $this->line('FFmpeg: '.trim($ffmpeg));
        if (trim($chrome) === 'NONE') {
            $this->error('Chrome headless missing. Install: choco install googlechrome / apt install chromium-browser / brew install --cask google-chrome');

            return 1;
        }
        if (trim($ffprobe) === 'NONE') {
            $this->error('FFprobe missing. Install: choco install ffmpeg / apt install ffmpeg / brew install ffmpeg');

            return 1;
        }
        $payloadPath = base_path($this->option('payload'));
        $outFile = storage_path('app/video_out/verify_'.time().'.mp4');
        $remotionDir = base_path('remotion');
        $this->line('Payload: '.($payloadPath && file_exists($payloadPath) ? $payloadPath : 'MISSING'));
        $this->line('Output: '.$outFile);
        if (! file_exists($remotionDir.'/dist/render-cli.js')) {
            $this->error('Remotion dist missing. Run remotion build first.');

            return 1;
        }
        $jsonFile = storage_path('app/verify_payload.json');
        if (file_exists($payloadPath)) {
            copy($payloadPath, $jsonFile);
        } else {
            file_put_contents($jsonFile, json_encode([
                'title' => 'Verification', 'language' => 'en', 'target_duration' => 5, 'scenes' => [
                    ['order' => 1, 'type' => 'intro', 'duration' => 5, 'headline' => 'Verify', 'description' => 'Verification', 'narration' => 'Testing', 'visual_keywords' => ['test']],
                ], 'template' => 'cyber',
            ]));
        }
        $cmd = 'cd '.escapeshellarg($remotionDir).' && node '.escapeshellarg('dist/render-cli.js').' --input '.escapeshellarg($jsonFile).' --output '.escapeshellarg($outFile).' > '.escapeshellarg($outFile.'.log').' 2>&1';
        $this->info('Starting Remotion render...');
        exec($cmd, $out, $code);
        if ($code !== 0) {
            $this->error('Remotion CLI failed (exit '.(int) $code.'). Log: '.$outFile.'.log');
            @unlink($outFile);
            @unlink($jsonFile);

            return 1;
        }
        if (! file_exists($outFile) || filesize($outFile) < 100) {
            $this->error('Output MP4 missing or too small.');
            @unlink($jsonFile);

            return 1;
        }
        $ffprobeBin = ($ffprobePath && file_exists($ffprobePath)) ? $ffprobePath : 'ffprobe';
        $ffprobe = @shell_exec(escapeshellarg($ffprobeBin).' -v error -select_streams v:0 -show_entries stream=codec_name,width,height,duration -of default=noprint_wrappers=1 '.escapeshellarg($outFile));
        $this->info('FFprobe result: '.($ffprobe ? trim($ffprobe) : 'NO OUTPUT'));
        $this->line('File size: '.filesize($outFile).' bytes');
        $this->line('Output: '.$outFile);
        $this->info('Verification complete. Real MP4 produced. Review FFprobe result above for verified metadata.');
        @unlink($jsonFile); // only temp payload cleaned; MP4 preserved for inspection

        return 0;
    }
}
