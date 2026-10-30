<?php

namespace App\Exceptions\Rendering;

class AudioPipelineException extends HyperFramesException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'audio');
    }
}
