<?php

namespace App\Models;

use App\Enums\ApprovalGateType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishingApprovalSession extends Model
{
    protected $table = 'publishing_approval_sessions';
    protected $fillable = [
        'video_script_id', 'status', 'first_approver_id', 'first_approved_at',
        'second_approver_id', 'second_approved_at', 'rejection_reason',
    ];

    public function videoScript(): BelongsTo
    {
        return $this->belongsTo(VideoScript::class);
    }
}
