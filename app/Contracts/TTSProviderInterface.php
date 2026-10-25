<?php

namespace App\Contracts;

interface TTSProviderInterface
{
    public function synthesize(string $text, array $options = []): array; // returns ['path'=>..., 'duration'=>..., 'format'=>'mp3']

    public function supportsLanguage(string $language): bool;

    public function getProviderName(): string;
}
