<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryClusterNews extends Model
{
    protected $table = 'story_cluster_news';

    protected $fillable = [
        'story_cluster_id',
        'news_id',
        'similarity',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'similarity' => 'float',
            'is_primary' => 'boolean',
        ];
    }

    public function storyCluster(): BelongsTo
    {
        return $this->belongsTo(StoryCluster::class);
    }

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }
}
