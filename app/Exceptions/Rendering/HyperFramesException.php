<?php

namespace App\Exceptions\Rendering;

use RuntimeException;

class HyperFramesException extends RuntimeException
{
    public function __construct(string $message, public readonly string $stage = 'render')
    {
        parent::__construct($message);
    }
}
