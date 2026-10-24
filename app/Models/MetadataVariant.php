<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetadataVariant extends Model
{
    protected $table = 'metadata_variants';
    protected $fillable = [
        'video_script_id', 'variant_key', 'title_text', 'description_text', 'tags_json', 'status',
    ];

    protected function casts(): array
    {
        return [
            'tags_json' => 'array',
        ];
    }

    public function videoScript(): BelongsTo
    {
        return $this->belongsTo(VideoScript::class);
    }
}
