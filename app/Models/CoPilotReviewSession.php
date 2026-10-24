<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoPilotReviewSession extends Model
{
    protected $table = 'co_pilot_review_sessions';
    protected $fillable = [
        'user_id', 'target_type', 'target_id', 'action', 'note', 'status',
    ];
}
