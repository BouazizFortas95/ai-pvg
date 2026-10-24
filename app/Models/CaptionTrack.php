<?php

namespace App\Models;

use App\Enums\CaptionTrackSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaptionTrack extends Model
{
    protected $table = 'caption_tracks';

    protected $fillable = [
        'video_script_id', 'language', 'source', 'style_preset',
        'word_timings', 'status', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => CaptionTrackSource::class,
            'word_timings' => 'array',
            'style_preset' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function videoScript(): BelongsTo
    {
        return $this->belongsTo(VideoScript::class);
    }

    public function isEstimated(): bool
    {
        return $this->source === CaptionTrackSource::Estimated;
    }

    public function isVerified(): bool
    {
        return $this->source === CaptionTrackSource::Verified;
    }
}
