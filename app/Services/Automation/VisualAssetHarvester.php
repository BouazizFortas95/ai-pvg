<?php

namespace App\Services\Automation;

use App\Enums\ApprovalGateType;
use App\Services\Automation\ApprovalService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class VisualAssetHarvester
{
    public function harvest(string $url, array $allowlist = []): array
    {
        $allowed = $allowlist ?: config('automation.assets.allowed_domains', []);
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        if ($host === '' || $this->isPrivateOrLoopback($host)) {
            throw new RuntimeException('SSRF blocked: private/loopback URL rejected.');
        }
        if ($allowed !== [] && ! in_array($host, $allowed, true)) {
            throw new RuntimeException('Domain not in allowlist: '.$host);
        }

        $mimeAllowed = ['image/jpeg', 'image/png', 'image/webp', 'video/mp4'];
        $maxBytes = config('automation.assets.max_bytes', 50_000_000);

        $response = Http::timeout(30)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Download failed: '.$url);
        }
        $mime = $response->header('Content-Type') ?? 'application/octet-stream';
        $mime = explode(';', $mime)[0];
        if (! in_array($mime, $mimeAllowed, true)) {
            throw new RuntimeException('MIME not allowed: '.$mime);
        }
        $body = $response->body();
        $bytes = strlen($body);
        if ($bytes > $maxBytes) {
            throw new RuntimeException('File exceeds max byte cap.');
        }

        $checksum = hash('sha256', $body);
        $existing = \App\Models\VisualAsset::where('checksum_sha256', $checksum)->first();
        if ($existing !== null) {
            return ['asset' => $existing, 'new' => false];
        }

        $path = 'assets/'.Str::random(16).'.'.($mime === 'video/mp4' ? 'mp4' : 'png');
        $fullPath = storage_path('app/public/'.$path);
        @mkdir(dirname($fullPath), 0755, true);
        file_put_contents($fullPath, $body);

        $asset = \App\Models\VisualAsset::create([
            'type' => $mime === 'video/mp4' ? 'video' : 'image',
            'storage_path' => $path,
            'source_url' => $url,
            'domain' => $host,
            'license_type' => 'unknown',
            'status' => 'pending',
            'mime_type' => $mime,
            'byte_size' => $bytes,
            'checksum_sha256' => $checksum,
            'metadata' => ['downloaded_at' => now()->toIso8601()],
        ]);

        if ($asset->license_type === 'unknown') {
            // hold behind approval gate; not injected until verified
            ApprovalService::openGate(ApprovalGateType::AssetLicense, ['asset_id' => $asset->id]);
        }

        return ['asset' => $asset, 'new' => true];
    }

    private function isPrivateOrLoopback(string $host): bool
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($ip !== false) return true;
        $lower = strtolower($host);
        return str_contains($lower, 'localhost') || str_contains($lower, '127.') || str_contains($lower, '10.') || str_contains($lower, '192.168.') || str_contains($lower, '172.');
    }
}
