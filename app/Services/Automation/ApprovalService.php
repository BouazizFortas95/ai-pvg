<?php

namespace App\Services\Automation;

use App\Enums\ApprovalGateStatus;
use App\Enums\ApprovalGateType;
use App\Enums\UserRole;
use App\Events\Automation\ApprovalDecided;
use App\Events\Automation\ApprovalRequested;
use App\Exceptions\Automation\ApprovalException;
use App\Models\ApprovalGate;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ApprovalService
{
    /**
     * Create (or return) a pending approval gate for a subject.
     */
    public function request(
        Model $subject,
        ApprovalGateType $type,
        ?User $requestedBy = null,
        ?DateTimeInterface $expiresAt = null,
        array $meta = [],
    ): ApprovalGate {
        if ($type === ApprovalGateType::PublicPublish) {
            $meta['require_dual'] = $this->dualPublicPublishConfigured();
        }

        $existing = ApprovalGate::query()
            ->where('gateable_type', $subject->getMorphClass())
            ->where('gateable_id', $subject->getKey())
            ->where('gate_type', $type->value)
            ->where('status', ApprovalGateStatus::Pending->value)
            ->latest('id')
            ->first();

        if ($existing !== null) {
            if (! $this->expireIfNeeded($existing)) {
                return $existing;
            }
        }

        $gate = ApprovalGate::create([
            'gateable_type' => $subject->getMorphClass(),
            'gateable_id' => $subject->getKey(),
            'gate_type' => $type,
            'status' => ApprovalGateStatus::Pending,
            'requested_by' => $requestedBy?->getKey(),
            'expires_at' => $expiresAt ?? now()->addHours(
                max(1, (int) config('automation.approval.ttl_hours', 72)),
            ),
            'meta' => $meta === [] ? null : $meta,
        ]);

        event(new ApprovalRequested($gate));

        return $gate;
    }

    /**
     * Record one approval. Public-publish gates can require two distinct
     * administrators; the first call remains pending, the second approves.
     */
    public function approve(ApprovalGate $gate, User $approver, ?string $note = null): ApprovalGate
    {
        $approver->refresh();

        if ($approver->role !== UserRole::Admin) {
            throw new AuthorizationException('Only administrators may decide automation approvals.');
        }

        $this->assertSystemApproverIsNotEligible($gate, $approver);

        $gate = $gate->fresh() ?? $gate;

        if ($this->expireIfNeeded($gate)) {
            throw ApprovalException::expired();
        }

        return DB::transaction(function () use ($gate, $approver, $note): ApprovalGate {
            $gate = ApprovalGate::query()->lockForUpdate()->findOrFail($gate->getKey());

            if (! $gate->isPending()) {
                throw ApprovalException::notPending();
            }

            if ($this->requiresDualApproval($gate)) {
                $meta = $gate->meta ?? [];

                if ($gate->first_approver_id === null) {
                    $meta['first_approval_note'] = $note;

                    $gate->forceFill([
                        'first_approver_id' => $approver->getKey(),
                        'first_approved_at' => now(),
                        'meta' => $meta,
                    ])->save();

                    return $gate->fresh();
                }

                if ((int) $gate->first_approver_id === (int) $approver->getKey()) {
                    throw ApprovalException::sameApprover();
                }

                $meta['second_approval_note'] = $note;

                $gate->forceFill([
                    'second_approver_id' => $approver->getKey(),
                    'second_approved_at' => now(),
                    'status' => ApprovalGateStatus::Approved,
                    'decided_by' => $approver->getKey(),
                    'decided_at' => now(),
                    'decision_note' => $note,
                    'meta' => $meta,
                ])->save();

                event(new ApprovalDecided($gate->fresh()));

                return $gate->fresh();
            }

            $gate->forceFill([
                'first_approver_id' => $approver->getKey(),
                'first_approved_at' => now(),
                'status' => ApprovalGateStatus::Approved,
                'decided_by' => $approver->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            event(new ApprovalDecided($gate->fresh()));

            return $gate->fresh();
        });
    }

    public function reject(ApprovalGate $gate, User $approver, string $reason): ApprovalGate
    {
        $approver->refresh();

        if ($approver->role !== UserRole::Admin) {
            throw new AuthorizationException('Only administrators may decide automation approvals.');
        }

        $this->assertSystemApproverIsNotEligible($gate, $approver);

        if (trim($reason) === '') {
            throw ApprovalException::rejectionReasonRequired();
        }

        $gate = $gate->fresh() ?? $gate;

        if ($this->expireIfNeeded($gate)) {
            throw ApprovalException::expired();
        }

        return DB::transaction(function () use ($gate, $approver, $reason): ApprovalGate {
            $gate = ApprovalGate::query()->lockForUpdate()->findOrFail($gate->getKey());

            if (! $gate->isPending()) {
                throw ApprovalException::notPending();
            }

            $gate->forceFill([
                'status' => ApprovalGateStatus::Rejected,
                'decided_by' => $approver->getKey(),
                'decided_at' => now(),
                'decision_note' => $reason,
            ])->save();

            event(new ApprovalDecided($gate->fresh()));

            return $gate->fresh();
        });
    }

    public function expirePending(): int
    {
        return ApprovalGate::query()
            ->where('status', ApprovalGateStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => ApprovalGateStatus::Expired->value,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function requiresDualApproval(ApprovalGate $gate): bool
    {
        if ($gate->gate_type !== ApprovalGateType::PublicPublish) {
            return false;
        }

        $meta = $gate->meta ?? [];

        if (array_key_exists('require_dual', $meta)) {
            return (bool) $meta['require_dual'];
        }

        return $this->dualPublicPublishConfigured();
    }

    public function dualPublicPublishConfigured(): bool
    {
        return (bool) config('automation.approval.require_dual_public_publish', false);
    }

    private function expireIfNeeded(ApprovalGate $gate): bool
    {
        if (! $gate->isPending() || $gate->expires_at === null || ! $gate->expires_at->isPast()) {
            return false;
        }

        $gate->forceFill([
            'status' => ApprovalGateStatus::Expired,
            'decided_at' => now(),
        ])->save();

        return true;
    }

    private function assertSystemApproverIsNotEligible(ApprovalGate $gate, User $approver): void
    {
        $systemUserId = config('automation.approval.system_user_id');

        if ($gate->requested_by === null || $systemUserId === null) {
            return;
        }

        if ((int) $approver->getKey() === (int) $systemUserId) {
            throw ApprovalException::systemApprover();
        }
    }
}
