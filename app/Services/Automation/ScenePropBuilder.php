<?php

namespace App\Services\Automation;

use App\Models\SceneAsset;

class ScenePropBuilder
{
    public function buildProps(int $videoScriptId, int $sceneIndex, ?array $directive): array
    {
        $assets = SceneAsset::where('video_script_id', $videoScriptId)
            ->where('scene_index', $sceneIndex)
            ->with('visualAsset')
            ->get()
            ->filter(fn (SceneAsset $sceneAsset): bool => $sceneAsset->visualAsset?->isVerified() === true)
            ->values();

        return [
            'scene_index' => $sceneIndex,
            'directive' => $directive,
            'assets' => $assets->map(function (SceneAsset $sceneAsset): array {
                $visualAsset = $sceneAsset->visualAsset;

                return [
                    'id' => (int) $visualAsset->getKey(),
                    'scene_asset_id' => (int) $sceneAsset->getKey(),
                    'path' => $visualAsset->storage_path,
                    'attribution' => $visualAsset->attribution_text ?? $sceneAsset->attribution_text,
                    'status' => $visualAsset->status,
                ];
            })->all(),
            'truthful_fallback' => $assets->isEmpty(),
        ];
    }
}
