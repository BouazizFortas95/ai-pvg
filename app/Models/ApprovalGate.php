<?php

namespace App\Models;

use App\Enums\ApprovalGateStatus;
use App\Enums\ApprovalGateType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalGate extends Model
{
    protected $fillable = [
        'gateable_type',
        'gateable_id',
        'gate_type',
        'status',
        'requested_by',
        'decided_by',
        'first_approver_id',
        'first_approved_at',
        'second_approver_id',
        'second_approved_at',
        'decision_note',
        'expires_at',
        'decided_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'gate_type' => ApprovalGateType::class,
            'status' => ApprovalGateStatus::class,
            'first_approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function gateable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approver_id');
    }

    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approver_id');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalGateStatus::Pending;
    }
}
