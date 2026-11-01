<?php

namespace App\Exceptions\Audio;

class ElevenLabsQuotaExceededException extends ElevenLabsException
{
    public function __construct(string $message = 'ElevenLabs rate limit or character quota exceeded.', int $statusCode = 429)
    {
        parent::__construct(
            $message,
            'Wait for the quota window to reset or upgrade your ElevenLabs subscription tier. You can also switch to local TTS.',
            $statusCode,
        );
    }
}

