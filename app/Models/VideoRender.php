<?php

namespace App\Models;

use App\Enums\VideoRenderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoRender extends Model
{
    use HasFactory;

    protected $table = 'video_renders';

    protected $fillable = ['video_script_id', 'template', 'status', 'output_path', 'duration', 'resolution', 'fps', 'error_message', 'started_at', 'completed_at'];

    protected $casts = ['status' => VideoRenderStatus::class, 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function videoScript(): BelongsTo
    {
        return $this->belongsTo(VideoScript::class);
    }
}
