<?php

namespace App\Models;

use App\Enums\VideoExportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoExport extends Model
{
    use HasFactory;

    protected $table = 'video_exports';

    protected $fillable = ['video_render_id', 'export_name', 'platform_preset', 'status', 'output_path', 'file_size', 'duration', 'resolution', 'validation_report', 'error_message', 'created_at', 'completed_at'];

    protected $casts = ['status' => VideoExportStatus::class, 'validation_report' => 'array', 'completed_at' => 'datetime'];

    public function videoRender(): BelongsTo
    {
        return $this->belongsTo(VideoRender::class);
    }
}
