<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SceneAsset extends Model
{
    protected $table = 'scene_assets';
    protected $fillable = [
        'video_script_id', 'scene_index', 'visual_asset_id', 'role',
        'directive_query', 'attribution_text', 'status',
    ];

    public function visualAsset(): BelongsTo
    {
        return $this->belongsTo(VisualAsset::class);
    }

    public function videoScript(): BelongsTo
    {
        return $this->belongsTo(VideoScript::class);
    }
}
