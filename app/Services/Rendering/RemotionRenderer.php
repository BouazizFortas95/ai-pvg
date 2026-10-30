<?php

namespace App\Services\Rendering;

use App\Contracts\VideoRenderer;
use App\Models\VideoRender;
use App\Services\VideoRenderingService;
use App\Support\RemotionRuntime;

class RemotionRenderer implements VideoRenderer
{
    public function __construct(
        protected VideoRenderingService $service
    ) {}

    public function render(VideoRender $render): VideoRender
    {
        return $this->service->render($render);
    }

    public function getDriver(): string
    {
        return 'remotion';
    }

    public function checkAvailability(): array
    {
        $cliPath = RemotionRuntime::cliPath();
        $chrome = RemotionRuntime::chromePath();
        $ffmpeg = RemotionRuntime::ffmpegPath();
        $ffprobe = RemotionRuntime::ffprobePath();

        $missing = [];

        if (! is_file($cliPath)) {
            $missing[] = 'Remotion CLI not built: '.$cliPath;
        }
        if (! $chrome) {
            $missing[] = 'Chrome headless not found';
        }
        if (! $ffmpeg) {
            $missing[] = 'FFmpeg not found';
        }
        if (! $ffprobe) {
            $missing[] = 'FFprobe not found';
        }

        return [
            'available' => $missing === [],
            'reason' => $missing !== [] ? implode('; ', $missing) : null,
        ];
    }
}
