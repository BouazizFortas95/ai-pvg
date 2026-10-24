<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YoutubePublishingState extends Model
{
    use HasFactory;

    protected $table = 'youtube_publishing_states';

    protected $fillable = ['video_export_id', 'user_id', 'provider_video_id', 'channel_id', 'status', 'visibility', 'metadata', 'error_message'];

    protected $casts = ['metadata' => 'array'];

    public function videoExport(): BelongsTo
    {
        return $this->belongsTo(VideoExport::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
