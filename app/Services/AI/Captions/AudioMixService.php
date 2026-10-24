<?php

namespace App\Services\AI\Captions;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class AudioMixService
{
    public function buildFilterGraph(array $assets, array $scenes): string
    {
        $inputs = [];
        $filters = [];
        $outputs = ['-map [outmix] -c copy -y'];

        $filterIndex = 1;
        foreach ($assets as $asset) {
            if ($asset['kind'] === 'narration' && ! empty($asset['storage_path'])) {
                $label = 'narr'.$filterIndex;
                $inputs[] = '-i '.escapeshellarg($asset['storage_path']);
                $filters[] = "[{$filterIndex}:a]";
                $filterIndex++;
            }
            if ($asset['kind'] === 'music' && ! empty($asset['storage_path'])) {
                $label = 'music'.$filterIndex;
                $inputs[] = '-i '.escapeshellarg($asset['storage_path']);
                $filters[] = "[{$filterIndex}:a]sidechaincompress=threshold=-20dB:ratio=4:release=50[a_ducked];";
                $filterIndex++;
            }
        }

        $graph = implode('', $filters);

        return implode(' ', $inputs).' -filter_complex "'.$graph.'" '.implode(' ', $outputs);
    }

    public function mixWithProcess(array $assets, array $scenes, string $outputPath, float $duckingDb = 18.0): string
    {
        $command = $this->buildFilterGraph($assets, $scenes);
        $proc = Process::fromShellCommandline($command, timeout: 300);
        $proc->run();

        if (! $proc->isSuccessful()) {
            throw new ProcessFailedException($proc);
        }

        return $outputPath;
    }

    public function mixSafe(array $assets, array $scenes, ?string $outputPath): ?string
    {
        $narration = array_filter($assets, fn ($a) => ($a['kind'] ?? '') === 'narration');
        if (empty($narration) || ($outputPath === null && ! file_exists($outputPath ?? ''))) {
            return null; // truthful fallback to unmixed / video-only
        }
        try {
            return $this->mixWithProcess($assets, $scenes, $outputPath ?? '');
        } catch (ProcessFailedException) {
            return null;
        }
    }
}
