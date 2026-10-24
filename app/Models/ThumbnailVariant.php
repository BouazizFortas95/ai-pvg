<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThumbnailVariant extends Model
{
    protected $table = 'thumbnail_variants';
    protected $fillable = ['video_render_id', 'storage_path', 'frame_offset_ms', 'status'];

    public function videoRender(): BelongsTo
    {
        return $this->belongsTo(VideoRender::class);
    }
}
