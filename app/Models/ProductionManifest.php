<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionManifest extends Model
{
    use HasFactory;

    protected $fillable = [
        'run_id',
        'pipeline_run_id',
        'video_script_id',
        'status',
        'manifest_data',
        'checksum_sha256',
    ];

    protected function casts(): array
    {
        return [
            'manifest_data' => 'array',
        ];
    }

    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class, 'pipeline_run_id');
    }

    public function videoScript(): BelongsTo
    {
        return $this->belongsTo(VideoScript::class, 'video_script_id');
    }
}

