<?php

namespace App\Exceptions\Rendering;

class HyperFramesUnavailableException extends HyperFramesException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'availability');
    }
}
