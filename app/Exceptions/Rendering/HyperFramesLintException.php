<?php

namespace App\Exceptions\Rendering;

class HyperFramesLintException extends HyperFramesException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'lint');
    }
}
