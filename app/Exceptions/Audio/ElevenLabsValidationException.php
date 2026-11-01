<?php

namespace App\Exceptions\Audio;

class ElevenLabsValidationException extends ElevenLabsException
{
    public function __construct(string $message, ?string $fixHint = null, int $statusCode = 422)
    {
        parent::__construct(
            $message,
            $fixHint ?? 'Ensure the requested model supports the text language and capabilities.',
            $statusCode,
        );
    }
}

