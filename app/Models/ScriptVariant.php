<?php

namespace App\Models;

use App\Enums\ScriptHookType;
use App\Enums\ScriptVariantType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptVariant extends Model
{
    protected $table = 'script_variants';

    protected $fillable = [
        'video_script_id',
        'variant_key',
        'variant_type',
        'hook_index',
        'hook_type',
        'content',
        'retention_rationale',
        'score',
        'selected',
        'source_news_ids',
        'prompt_version',
        'model_version',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'variant_type' => ScriptVariantType::class,
            'hook_type' => ScriptHookType::class,
            'hook_index' => 'integer',
            'score' => 'float',
            'selected' => 'boolean',
            'source_news_ids' => 'array',
            'meta' => 'array',
        ];
    }

    public function videoScript(): BelongsTo
    {
        return $this->belongsTo(VideoScript::class);
    }

    public function isHook(): bool
    {
        return $this->variant_type === ScriptVariantType::Hook;
    }
}
