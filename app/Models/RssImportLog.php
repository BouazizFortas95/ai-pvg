<?php

namespace App\Models;

use Database\Factories\RssImportLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RssImportLog extends Model
{
    /** @use HasFactory<RssImportLogFactory> */
    use HasFactory;

    protected $table = 'rss_import_logs';

    protected $fillable = [
        'news_source_id',
        'status',
        'total_items',
        'imported_items',
        'duplicate_items',
        'skipped_items',
        'failed_items',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function newsSource(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class);
    }
}
