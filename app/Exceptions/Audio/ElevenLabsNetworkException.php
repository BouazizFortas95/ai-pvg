<?php

namespace App\Exceptions\Audio;

class ElevenLabsNetworkException extends ElevenLabsException
{
    public function __construct(string $message = 'Network connection timeout or failure contacting ElevenLabs API.')
    {
        parent::__construct(
            $message,
            'Check your internet connection, proxy settings, or ELEVENLABS_BASE_URL. The circuit breaker will protect against repeated hangs.',
            0,
        );
    }
}

