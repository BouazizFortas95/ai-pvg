<?php

namespace App\Services\Rendering;

use App\Contracts\VideoRenderer;
use App\Models\VideoRender;

class HyperFramesRenderer implements VideoRenderer
{
    public function __construct(
        protected HyperFramesRenderService $renderService,
    ) {}

    public function render(VideoRender $render): VideoRender
    {
        return $this->renderService->render($render);
    }

    public function getDriver(): string
    {
        return 'hyperframes';
    }

    /**
     * @return array{available: bool, reason?: string}
     */
    public function checkAvailability(): array
    {
        $status = $this->renderService->checkAvailability();

        return [
            'available' => (bool) $status['available'],
            'reason' => $status['reason'],
        ];
    }
}
