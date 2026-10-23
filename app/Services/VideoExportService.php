<?php

namespace App\Services;

use App\Models\VideoExport;

class VideoExportService
{
    protected function hasFFprobe(): bool
    {
        return shell_exec('which ffprobe 2>/dev/null') !== null;
    }

    protected function extractMetadata(string $path): ?array
    {
        if (! file_exists($path)) {
            return null;
        }
        $cmd = 'ffprobe -v error -select_streams v:0 -show_entries stream=width,height,duration,codec_name -of csv=s=x:p=0 '.escapeshellarg($path);
        $output = shell_exec($cmd);
        if (! $output) {
            return ['verified' => false, 'note' => 'ffprobe unavailable or output empty'];
        }
        $line = trim($output);
        $parts = explode(',', $line);

        return [
            'verified' => true,
            'codec' => $parts[3] ?? null,
            'width' => is_numeric($parts[0] ?? null) ? (int) $parts[0] : null,
            'height' => is_numeric($parts[1] ?? null) ? (int) $parts[1] : null,
            'duration' => is_numeric($parts[2] ?? null) ? (float) $parts[2] : null,
            'raw' => $line,
        ];
    }

    public function validateAndRecord(VideoExport $export): VideoExport
    {
        $render = $export->videoRender;
        if (! $render || $render->status !== 'completed') {
            $export->update(['status' => 'failed', 'error_message' => 'Source render not completed', 'completed_at' => now()]);

            return $export->fresh();
        }
        $sourcePath = $render->output_path ? storage_path('app/'.$render->output_path) : null;
        $exists = $sourcePath && file_exists($sourcePath) && filesize($sourcePath) > 1000;
        $metadata = $exists ? $this->extractMetadata($sourcePath) : null;
        $validated = ! ($metadata === null || ($metadata['verified'] === false && $exists === false));
        $report = [
            'source_exists' => $exists,
            'ffprobe_available' => $this->hasFFprobe(),
            'metadata' => $metadata,
            'preset_validation' => PlatformPresetService::validate($export->platform_preset, $metadata['duration'] ?? $render->duration ?? 0, $render->resolution ?? '1080x1920'),
        ];
        $export->update([
            'status' => $validated ? 'completed' : 'failed',
            'output_path' => $exists ? $render->output_path : null,
            'file_size' => $exists ? filesize($sourcePath) : null,
            'duration' => $metadata['duration'] ?? $render->duration,
            'resolution' => $render->resolution ?? '1080x1920',
            'validation_report' => $report,
            'completed_at' => now(),
            'error_message' => $validated ? null : 'Video file missing or metadata unverified',
        ]);

        return $export->fresh();
    }
}
