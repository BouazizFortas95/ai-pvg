<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishingApprovalSession extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'publishing_approval_sessions';

    protected $fillable = [
        'video_script_id', 'status', 'first_approver_id', 'first_approved_at',
        'second_approver_id', 'second_approved_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'first_approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
        ];
    }

    public function videoScript(): BelongsTo
    {
        return $this->belongsTo(VideoScript::class);
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approver_id');
    }

    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approver_id');
    }

    public function hasDistinctApprovers(): bool
    {
        return $this->first_approver_id !== null
            && $this->second_approver_id !== null
            && (int) $this->first_approver_id !== (int) $this->second_approver_id;
    }
}
