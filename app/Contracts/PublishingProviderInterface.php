<?php

namespace App\Contracts;

interface PublishingProviderInterface
{
    public function getName(): string;

    public function isConfigured(): bool;

    public function initiateAuth(): string; // returns authorization URL

    public function handleCallback(string $code, string $state): array; // returns ['access_token'=>..., 'refresh_token'=>...]

    public function upload(array $exportMeta, string $videoPath): array; // returns ['provider_video_id'=>..., 'url'=>..., 'status'=>'uploaded']

    public function disconnect(): bool;
}
