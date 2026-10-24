<?php

namespace App\Services\Publishing;

use App\Models\CoPilotReviewSession;

class CoPilotReviewService
{
    public function review(int $userId, int $targetId, string $action, ?string $note = null): bool
    {
        CoPilotReviewSession::create([
            'user_id' => $userId,
            'target_type' => 'script',
            'target_id' => $targetId,
            'action' => $action,
            'note' => $note,
            'status' => 'completed',
        ]);
        return true;
    }
}
