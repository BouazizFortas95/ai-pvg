<?php

namespace App\Jobs;

use App\Enums\VideoRenderStatus;
use App\Models\VideoRender;
use App\Services\VideoRenderingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RenderVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $videoRenderId) {}

    public function uniqueId(): string
    {
        return 'video-render-'.$this->videoRenderId;
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(): void
    {
        $render = VideoRender::find($this->videoRenderId);
        $status = $render?->status instanceof \BackedEnum ? $render->status->value : $render?->status;
        if (! $render || $status === VideoRenderStatus::Completed->value) {
            return;
        }
        $service = app(VideoRenderingService::class);
        $service->render($render);
    }
}
