<?php

namespace App\Models;

use App\Enums\TriageBand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsScore extends Model
{
    protected $table = 'news_scores';

    protected $fillable = [
        'news_id',
        'velocity_score',
        'keyword_heat_score',
        'novelty_score',
        'source_diversity_score',
        'recency_score',
        'composite_score',
        'band',
        'breakdown',
        'scored_at',
    ];

    protected function casts(): array
    {
        return [
            'velocity_score' => 'float',
            'keyword_heat_score' => 'float',
            'novelty_score' => 'float',
            'source_diversity_score' => 'float',
            'recency_score' => 'float',
            'composite_score' => 'float',
            'band' => TriageBand::class,
            'breakdown' => 'array',
            'scored_at' => 'datetime',
        ];
    }

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

    /**
     * A compact, ordered component list used by read-only UI surfaces.
     *
     * @return array<string, float>
     */
    public function componentScores(): array
    {
        return [
            'velocity' => (float) $this->velocity_score,
            'keyword_heat' => (float) $this->keyword_heat_score,
            'novelty' => (float) $this->novelty_score,
            'source_diversity' => (float) $this->source_diversity_score,
            'recency' => (float) $this->recency_score,
        ];
    }
}
