<?php

namespace Database\Factories;

use App\Models\NewsSource;
use App\Models\RssImportLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class RssImportLogFactory extends Factory
{
    protected $model = RssImportLog::class;

    public function definition(): array
    {
        return [
            'news_source_id' => NewsSource::factory(),
            'status' => 'completed',
            'total_items' => 5,
            'imported_items' => 3,
            'duplicate_items' => 1,
            'skipped_items' => 0,
            'failed_items' => 0,
            'error_message' => null,
            'started_at' => now(),
            'completed_at' => now(),
        ];
    }
}
