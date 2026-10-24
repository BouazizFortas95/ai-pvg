<?php

namespace App\Services\Automation;

class ScenePropBuilder
{
    public function buildProps(int $videoScriptId, int $sceneIndex, ?array $directive): array
    {
        $query = $directive['b_roll_query'] ?? ($directive['visual_keywords'][0] ?? null);
        $assets = \App\Models\SceneAsset::where('video_script_id', $videoScriptId)
            ->where('scene_index', $sceneIndex)
            ->with('visualAsset')
            ->get()
            ->map(fn ($sa) => $sa->visualAsset?->isVerified() ? $sa : null)
            ->filter()
            ->values();
        return [
            'scene_index' => $sceneIndex,
            'directive' => $directive,
            'assets' => $assets->map(fn ($a) => [
                'id' => $a->id,
                'path' => $a->storage_path,
                'attribution' => $a->attribution_text,
                'status' => $a->status,
            ])->all(),
            'truthful_fallback' => empty($assets) || $assets->every(fn ($a) => ! $a->isVerified()),
        ];
    }
}
