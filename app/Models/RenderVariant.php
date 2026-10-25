<?php

namespace App\Models;

use App\Enums\PresetFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RenderVariant extends Model
{
    protected $table = 'render_variants';

    protected $fillable = [
        'video_render_id', 'preset_name', 'resolution_width', 'resolution_height',
        'storage_path', 'status', 'checksum_sha256',
    ];

    protected function casts(): array
    {
        return [
            'preset_name' => PresetFormat::class,
            'checksum_sha256' => 'string',
        ];
    }

    public function videoRender(): BelongsTo
    {
        return $this->belongsTo(VideoRender::class);
    }
}
