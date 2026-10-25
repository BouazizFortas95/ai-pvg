<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoPilotReviewSession extends Model
{
    public const TARGET_SCRIPT = 'script';

    public const TARGET_RENDER = 'render';

    public const TARGET_ASSET = 'asset';

    public const ACTION_APPROVE = 'approve';

    public const ACTION_REJECT = 'reject';

    protected $table = 'co_pilot_review_sessions';

    protected $fillable = [
        'user_id', 'target_type', 'target_id', 'action', 'note', 'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
