<?php

namespace App\Contracts;

use App\Models\VideoRender;

interface VideoRenderer
{
    /**
     * Render a video from an approved script.
     *
     * @return VideoRender The updated render model with status and output path
     */
    public function render(VideoRender $render): VideoRender;

    /**
     * Get the driver name.
     */
    public function getDriver(): string;

    /**
     * Check if the renderer is available (binaries, config, etc.).
     *
     * @return array{available: bool, reason?: string}
     */
    public function checkAvailability(): array;
}
