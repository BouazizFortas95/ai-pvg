<?php

namespace App\Services\Automation;

class RemotionPropInjector
{
    public function inject(array $scriptPayload, array $props): array
    {
        $visualAssets = $props['assets'] ?? [];
        $verified = array_filter($visualAssets, fn ($a) => ($a['status'] ?? '') === 'verified');
        $payload = $scriptPayload;
        if (! empty($verified)) {
            $payload['visualAssets'] = array_values($verified);
            $payload['sceneProps'] = $props['scene_index'] ?? null;
        } else {
            $payload['visualAssets'] = null; // truthful fallback
        }

        return $payload;
    }
}
