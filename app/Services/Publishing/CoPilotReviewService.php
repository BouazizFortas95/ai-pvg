<?php

namespace App\Services\Publishing;

use App\Enums\UserRole;
use App\Models\CoPilotReviewSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Admin-only Co-Pilot review inbox.
 *
 * Review records are append-only audit entries. This service never publishes,
 * uploads, renders, or changes the reviewed subject.
 */
class CoPilotReviewService
{
    public function review(
        int $userId,
        int $targetId,
        string $action,
        ?string $note = null,
        string $targetType = CoPilotReviewSession::TARGET_SCRIPT,
    ): bool {
        $user = User::query()->find($userId);

        if ($user === null || $user->role !== UserRole::Admin) {
            throw new AuthorizationException('Only administrators can review Co-Pilot items.');
        }

        if (! in_array($targetType, [
            CoPilotReviewSession::TARGET_SCRIPT,
            CoPilotReviewSession::TARGET_RENDER,
            CoPilotReviewSession::TARGET_ASSET,
        ], true)) {
            throw new InvalidArgumentException('Unsupported Co-Pilot review target type.');
        }

        if (! in_array($action, [
            CoPilotReviewSession::ACTION_APPROVE,
            CoPilotReviewSession::ACTION_REJECT,
        ], true)) {
            throw new InvalidArgumentException('Unsupported Co-Pilot review action.');
        }

        if (! Schema::hasTable('co_pilot_review_sessions')) {
            throw new RuntimeException('Co-Pilot review tables are not available.');
        }

        CoPilotReviewSession::query()->create([
            'user_id' => $user->getKey(),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action' => $action,
            'note' => $note,
            'status' => 'completed',
        ]);

        return true;
    }
}
