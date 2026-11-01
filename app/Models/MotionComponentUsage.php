<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MotionComponentUsage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'pipeline_run_id',
        'motion_component_id',
        'scene_index',
        'applied_params',
        'seed',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'scene_index' => 'integer',
            'applied_params' => 'array',
            'seed' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(MotionComponent::class, 'motion_component_id');
    }

    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class, 'pipeline_run_id');
    }
}

