<?php

namespace App\Exceptions\Audio;

class ElevenLabsAuthException extends ElevenLabsException
{
    public function __construct(string $message = 'Invalid or missing ElevenLabs API key.', int $statusCode = 401)
    {
        parent::__construct(
            $message,
            'Check ELEVENLABS_API_KEY in your .env file or verify permissions in the ElevenLabs dashboard.',
            $statusCode,
        );
    }
}

