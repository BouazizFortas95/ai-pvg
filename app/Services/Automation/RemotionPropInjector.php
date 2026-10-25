<?php

namespace App\Services\Automation;

class RemotionPropInjector
{
    public function inject(array $scriptPayload, array $props): array
    {
        $visualAssets = $props['assets'] ?? [];
        $verified = array_filter($visualAssets, fn ($a) => ($a['status'] ?? '') === 'verified');
        $payload = $scriptPayload;
        $payload['visualAssets'] = empty($verified) ? null : array_values($verified); // null = truthful fallback
        $payload['sceneProps'] = $props;

        return $payload;
    }
}
