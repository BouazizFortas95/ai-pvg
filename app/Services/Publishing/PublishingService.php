<?php

namespace App\Services\Publishing;

use App\Contracts\PublishingProviderInterface;
use App\Models\YoutubePublishingState;

class PublishingService
{
    protected PublishingProviderInterface $provider;

    public function __construct(string $provider = 'youtube')
    {
        $this->provider = match ($provider) {
            'youtube' => new YouTubePublishingProvider,
            default => new YouTubePublishingProvider,
        };
    }

    public function provider(): PublishingProviderInterface
    {
        return $this->provider;
    }

    public function initiateUpload(int $exportId, int $userId, array $metadata = [], string $visibility = 'private'): YoutubePublishingState
    {
        if (! $this->provider->isConfigured()) {
            throw new \RuntimeException('Publishing provider not configured');
        }
        $state = YoutubePublishingState::create([
            'video_export_id' => $exportId,
            'user_id' => $userId,
            'status' => 'pending',
            'visibility' => $visibility,
            'metadata' => $metadata,
        ]);

        return $state;
    }

    public function confirmUpload(int $stateId): array
    {
        $state = YoutubePublishingState::findOrFail($stateId);
        $export = $state->videoExport;
        $path = $export && $export->output_path ? storage_path('app/'.$export->output_path) : null;
        if (! $path || ! file_exists($path) || filesize($path) < 100) {
            $state->update(['status' => 'failed', 'error_message' => 'Export file missing or too small']);
            throw new \RuntimeException('Export file missing');
        }
        if ($export && $export->status !== 'completed') {
            $state->update(['status' => 'failed', 'error_message' => 'Export not validated']);
            throw new \RuntimeException('Export validation incomplete');
        }
        $result = $this->provider->upload($state->metadata ?? [], $path);
        $state->update([
            'provider_video_id' => $result['provider_video_id'] ?? null,
            'status' => 'uploaded',
        ]);

        return $result;
    }

    public function disconnect(): bool
    {
        return $this->provider->disconnect();
    }
}
