<?php

namespace App\Models;

use App\Enums\ScriptFactCheckVerdict;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptFactCheck extends Model
{
    protected $table = 'script_fact_checks';

    protected $fillable = [
        'video_script_id',
        'script_variant_id',
        'claim_hash',
        'claim',
        'verdict',
        'source_news_ids',
        'evidence',
        'critic_score',
        'checker_version',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'verdict' => ScriptFactCheckVerdict::class,
            'source_news_ids' => 'array',
            'critic_score' => 'float',
            'meta' => 'array',
        ];
    }

    public function videoScript(): BelongsTo
    {
        return $this->belongsTo(VideoScript::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ScriptVariant::class, 'script_variant_id');
    }

    public function blocksApproval(): bool
    {
        return $this->verdict instanceof ScriptFactCheckVerdict
            && $this->verdict->blocksApproval();
    }
}
