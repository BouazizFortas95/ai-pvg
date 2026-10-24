<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioAsset extends Model
{
    protected $table = 'audio_assets';

    protected $fillable = [
        'video_script_id', 'kind', 'storage_path', 'license_source',
        'duration_seconds', 'loudness_lu', 'checksum_sha256',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'float',
            'loudness_lu' => 'float',
        ];
    }

    public function videoScript(): BelongsTo
    {
        return $this->belongsTo(VideoScript::class);
    }
}
