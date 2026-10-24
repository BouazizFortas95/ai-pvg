<?php

namespace App\Models;

use App\Enums\VideoScriptStatus;
use Database\Factories\VideoScriptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoScript extends Model
{
    /** @use HasFactory<VideoScriptFactory> */
    use HasFactory;

    protected $table = 'video_scripts';

    protected $fillable = [
        'news_id',
        'title',
        'language',
        'target_duration',
        'estimated_duration',
        'narration',
        'scenes',
        'status',
        'ai_provider',
        'ai_model',
        'error_message',
        'generated_at',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'scenes' => 'array',
            'status' => VideoScriptStatus::class,
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ScriptVariant::class);
    }

    public function factChecks(): HasMany
    {
        return $this->hasMany(ScriptFactCheck::class);
    }
}
