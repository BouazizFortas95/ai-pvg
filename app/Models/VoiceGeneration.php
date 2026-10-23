<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class VoiceGeneration extends Model
{
    use HasFactory;
    protected $table = 'voice_generations';
    protected $fillable = ['video_script_id','provider','voice','language','status','audio_path','duration','error_message','started_at','completed_at'];
    protected $casts = ['status'=>\App\Enums\VoiceGenerationStatus::class,'started_at'=>'datetime','completed_at'=>'datetime'];
    public function videoScript(): BelongsTo { return $this->belongsTo(VideoScript::class); }
}
