<?php

namespace App\Exceptions\Rendering;

class HyperFramesDisabledException extends HyperFramesException
{
    public function __construct(string $message = 'HyperFrames rendering is disabled (config switch or render.hyperframes feature flag is off).')
    {
        parent::__construct($message, 'gate');
    }
}
