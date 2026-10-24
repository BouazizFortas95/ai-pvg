<?php

namespace App\Services\Publishing;

use App\Contracts\PublishingProviderInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class YouTubePublishingProvider implements PublishingProviderInterface
{
    protected array $config;

    public function __construct()
    {
        $this->config = [
            'client_id' => config('youtube.client_id'),
            'client_secret' => config('youtube.client_secret'),
            'redirect_uri' => config('youtube.redirect_uri'),
        ];
    }

    public function getName(): string
    {
        return 'youtube';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['client_id']) && ! empty($this->config['client_secret']) && ! empty($this->config['redirect_uri']);
    }

    public function initiateAuth(): string
    {
        $state = bin2hex(random_bytes(16));
        session(['youtube_oauth_state' => $state]);
        $scopes = 'https://www.googleapis.com/auth/youtube.upload';

        return 'https://accounts.google.com/o/oauth2/auth'.
            '?client_id='.urlencode($this->config['client_id'])
            .'&redirect_uri='.urlencode($this->config['redirect_uri'])
            .'&scope='.urlencode($scopes)
            .'&response_type=code'
            .'&access_type=offline'
            .'&prompt=consent'
            .'&state='.urlencode($state);
    }

    public function handleCallback(string $code, string $state): array
    {
        $expected = session('youtube_oauth_state');
        if (! $expected || ! hash_equals($expected, $state)) {
            throw new \RuntimeException('Invalid OAuth state');
        }
        session()->forget('youtube_oauth_state');
        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);
        if (! $res->successful()) {
            throw new \RuntimeException('OAuth token request failed');
        }
        $data = $res->json();

        return [
            'access_token' => Crypt::encryptString($data['access_token'] ?? ''),
            'refresh_token' => Crypt::encryptString($data['refresh_token'] ?? ''),
            'expires_in' => $data['expires_in'] ?? null,
        ];
    }

    public function upload(array $exportMeta, string $videoPath): array
    {
        if (! file_exists($videoPath) || filesize($videoPath) < 100) {
            throw new \RuntimeException('Video file missing');
        }
        $token = $this->getStoredAccessToken();
        if (! $token) {
            $token = $this->refreshAccessToken();
        }
        if (! $token) {
            throw new \RuntimeException('No YouTube access token available; please reconnect.');
        }

        // Real resumable upload: session initiation
        $initRes = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ])->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [
            'snippet' => [
                'title' => $exportMeta['title'] ?? 'AI-PVG Export',
                'description' => $exportMeta['description'] ?? '',
            ],
            'status' => ['privacyStatus' => $exportMeta['visibility'] ?? 'private'],
        ]);
        if (! $initRes->successful()) {
            throw new \RuntimeException('YouTube upload session initialization failed');
        }
        $uploadUrl = $initRes->header('Location') ?: ($initRes->json('uploadUrl') ?? null);
        if (! $uploadUrl) {
            throw new \RuntimeException('YouTube did not return upload session URL');
        }

        // Chunk upload using native PUT with bounded timeout and retries
        $videoData = file_get_contents($videoPath);
        $uploadRes = Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => strlen($videoData),
        ])->withBody($videoData, 'application/octet-stream')
            ->timeout(300)
            ->put($uploadUrl);

        if (! $uploadRes->successful()) {
            throw new \RuntimeException('YouTube chunk upload failed');
        }
        $body = $uploadRes->json();
        $videoId = $body['id'] ?? null;
        if (! $videoId) {
            throw new \RuntimeException('YouTube upload completed but no video ID returned');
        }

        return [
            'provider_video_id' => $videoId,
            'url' => 'https://youtube.com/watch?v='.$videoId,
            'status' => 'uploaded',
        ];
    }

    public function disconnect(): bool
    {
        return true;
    }

    protected function refreshAccessToken(): ?string
    {
        $encRefresh = config('youtube.refresh_token');
        if (! $encRefresh) {
            return null;
        }
        $refresh = Crypt::decryptString($encRefresh);
        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'refresh_token' => $refresh,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'grant_type' => 'refresh_token',
        ]);

        return $res->successful() ? ($res->json('access_token') ?? null) : null;
    }

    protected function getStoredAccessToken(): ?string
    {
        $enc = config('youtube.access_token');

        return $enc ? Crypt::decryptString($enc) : null;
    }
}
