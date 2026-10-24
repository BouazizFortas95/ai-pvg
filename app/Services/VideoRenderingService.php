<?php
namespace App\Services;
use App\Models\VideoRender;
use App\Models\VideoScript;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
class VideoRenderingService
{
    protected string $remotionDir = 'remotion';
    protected string $outputDir = 'public/videos';

    public function render(VideoRender $render): VideoRender
    {
        $script = $render->videoScript;
        if (! $script || $script->status !== 'approved') {
            $render->update(['status'=>'failed','error_message'=>'Script not approved']);
            return $render->fresh();
        }
        $scenes = $script->scenes ?? [];
        if (empty($scenes)) {
            $render->update(['status'=>'failed','error_message'=>'No scenes']);
            return $render->fresh();
        }
        $payload = [
            'scenes' => $scenes,
            'title' => $script->title,
            'language' => $script->language,
            'target_duration' => $script->target_duration,
            'template' => $render->template,
        ];
        $jsonFile = storage_path('app/payload_'.($render->id).'.json');
        $outputFile = $this->outputDir.'/render_'.($render->id).'.mp4';
        file_put_contents($jsonFile, json_encode($payload));
        $cmd = 'node '.base_path($this->remotionDir.'/dist/render-cli.js').' --input '.escapeshellarg($jsonFile).' --output '.escapeshellarg($outputFile);
        $render->update(['status'=>'rendering','started_at'=>now(),'completed_at'=>null]);
        $process = proc_open($cmd, [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (is_resource($process)) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            proc_close($process);
        }
        $verified = false;
        $metadataNote = 'unverified';
        if (shell_exec('which ffprobe 2>/dev/null')) {
            $ffprobe = @shell_exec('ffprobe -v error -select_streams v:0 -show_entries stream=codec_name,width,height,duration -of default=noprint_wrappers=1 '.escapeshellarg($outputFile));
            $verified = (bool) $ffprobe;
            $metadataNote = $verified ? 'verified' : 'ffprobe unavailable or empty';
        }
        if (file_exists($outputFile) && filesize($outputFile) > 100) {
            $render->update([
                'status'=>'completed',
                'completed_at'=>now(),
                'output_path'=>$outputFile,
                'duration'=>($script->target_duration) ?: 30,
                'resolution'=>'1080x1920',
            ]);
        } else {
            $render->update([
                'status'=>'failed',
                'error_message'=>'Render output missing or too small.',
                'completed_at'=>now(),
            ]);
        }
        @unlink($jsonFile);
        return $render->fresh();
    }
}
