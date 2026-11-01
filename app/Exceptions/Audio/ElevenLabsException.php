<?php

namespace App\Exceptions\Audio;

use RuntimeException;
use Throwable;

class ElevenLabsException extends RuntimeException
{
    public function __construct(
        string $message = '',
        public readonly ?string $fixHint = null,
        public readonly int $statusCode = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}

