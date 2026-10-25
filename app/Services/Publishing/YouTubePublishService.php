<?php

namespace App\Services\Publishing;

use App\Enums\ApprovalGateStatus;
use App\Enums\ApprovalGateType;
use App\Enums\UserRole;
use App\Models\ApprovalGate;
use App\Models\PublishingApprovalSession;
use App\Models\User;
use App\Models\VideoScript;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Phase H public-publish guard.
 *
 * This service never performs a network upload. It verifies that a public
 * transition is backed by two distinct administrator identities, either via
 * an approved PublicPublish approval gate or an approved Phase H approval
 * session. Missing schema, disabled gates, same-admin approvals, and
 * non-admin approvers all fail closed.
 */
class YouTubePublishService
{
    public function publishVideoScript(int $videoScriptId): bool
    {
        $this->assertPublicPublishAuthorized($videoScriptId);

        return true;
    }

    public function assertPublicPublishAuthorized(int $videoScriptId): void
    {
        app(PublishingGate::class)->assertEnabled();

        if (! Schema::hasTable('publishing_approval_sessions')) {
            throw new RuntimeException('Publishing approval tables are not available.');
        }

        $session = PublishingApprovalSession::query()
            ->where('video_script_id', $videoScriptId)
            ->latest('id')
            ->first();

        $gate = $this->latestPublicPublishGate($videoScriptId);

        if ($session === null && $gate === null) {
            throw new RuntimeException('No approved dual-admin authorization for this script.');
        }

        if ($session !== null) {
            if ($session->status !== PublishingApprovalSession::STATUS_APPROVED) {
                throw new RuntimeException('The publishing approval session is not approved.');
            }

            $this->assertDistinctAdminApprovers(
                $session->first_approver_id,
                $session->second_approver_id,
            );
        }

        if ($gate !== null) {
            if ($gate->status !== ApprovalGateStatus::Approved) {
                throw new RuntimeException('The PublicPublish approval gate is not approved.');
            }

            $this->assertDistinctAdminApprovers(
                $gate->first_approver_id,
                $gate->second_approver_id,
            );
        }
    }

    private function latestPublicPublishGate(int $videoScriptId): ?ApprovalGate
    {
        if (! Schema::hasTable('approval_gates')) {
            return null;
        }

        return ApprovalGate::query()
            ->where('gateable_type', (new VideoScript)->getMorphClass())
            ->where('gateable_id', $videoScriptId)
            ->where('gate_type', ApprovalGateType::PublicPublish->value)
            ->latest('id')
            ->first();
    }

    private function assertDistinctAdminApprovers(?int $firstApproverId, ?int $secondApproverId): void
    {
        if ($firstApproverId === null
            || $secondApproverId === null
            || (int) $firstApproverId === (int) $secondApproverId) {
            throw new RuntimeException('Public publishing requires two distinct administrators.');
        }

        $approvers = User::query()
            ->whereKey([$firstApproverId, $secondApproverId])
            ->get()
            ->keyBy(fn (User $user): int => (int) $user->getKey());

        if ($approvers->count() !== 2) {
            throw new RuntimeException('Both publishing approvers must be existing administrators.');
        }

        foreach ($approvers as $approver) {
            if ($approver->role !== UserRole::Admin) {
                throw new RuntimeException('Both publishing approvers must be administrators.');
            }
        }
    }
}
