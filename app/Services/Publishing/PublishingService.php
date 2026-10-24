<?php

namespace App\Services\Publishing;

use App\Contracts\PublishingProviderInterface;
use App\Enums\VideoExportStatus;
use App\Models\YoutubePublishingState;
use App\Support\MediaPath;

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
        $path = MediaPath::resolve($export?->output_path);
        if (! $path || ! is_file($path) || (filesize($path) ?: 0) < 100) {
            $state->update(['status' => 'failed', 'error_message' => 'Export file missing or too small']);
            throw new \RuntimeException('Export file missing');
        }

        $exportStatus = $export?->status instanceof \BackedEnum
            ? $export->status->value
            : $export?->status;

        if ($export && $exportStatus !== VideoExportStatus::Completed->value) {
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
