<?php

namespace App\Modules\Foundation\Services;

use App\Models\User;
use App\Modules\Activity\Concerns\RecordsUserActions;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Access\Approval;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes users.approval_status. The columns are not mass assignable, so nothing can
 * approve an account by passing an array through a validator — it has to come through here, where the
 * moderator is recorded and the action is logged.
 */
final class UserApprovalService
{
    use RecordsUserActions;

    /** @throws DomainException when the moderator is the account holder, or the account is not awaiting a decision */
    public function approve(User $user, User $moderator): User
    {
        $this->assertNotSelf($user, $moderator);
        $this->assertPending($user);

        DB::transaction(function () use ($user, $moderator): void {
            $user->forceFill([
                'approval_status' => Approval::APPROVED,
                'approved_by' => $moderator->getKey(),
                'approved_at' => now(),
                'rejected_reason' => null,
            ])->save();
        });

        $this->recordAction(ActionKeys::USER_APPROVED, $user, ['name' => $user->name], $moderator);

        return $user;
    }

    /** @throws DomainException when the moderator is the account holder, or the account is not awaiting a decision */
    public function reject(User $user, User $moderator, string $reason): User
    {
        $this->assertNotSelf($user, $moderator);
        $this->assertPending($user);

        DB::transaction(function () use ($user, $moderator, $reason): void {
            $user->forceFill([
                'approval_status' => Approval::REJECTED,
                'approved_by' => $moderator->getKey(),
                'approved_at' => now(),
                'rejected_reason' => $reason,
            ])->save();
        });

        $this->recordAction(ActionKeys::USER_REJECTED, $user, ['name' => $user->name, 'reason' => $reason], $moderator);

        return $user;
    }

    /**
     * Mark an account approved without a moderator decision: accounts an administrator created directly, and
     * the seeded accounts. There is no self-service path into this — the caller is already an admin screen.
     *
     * It saves. The earlier version only filled the attributes and left every caller to remember `->save()`,
     * which a name beginning "approve" does not warn anyone about; a caller that forgot would have created an
     * account that looked approved in memory and was pending in the database.
     */
    public function approveOnCreation(User $user, ?User $by = null): User
    {
        $user->forceFill([
            'approval_status' => Approval::APPROVED,
            'approved_by' => $by?->getKey(),
            'approved_at' => now(),
        ])->save();

        return $user;
    }

    private function assertNotSelf(User $user, User $moderator): void
    {
        if ($user->is($moderator)) {
            throw new DomainException(__('approval.errors.cannot_moderate_self'));
        }
    }

    /**
     * Moderation decides a *signup*. It is not a switch for turning working accounts off.
     *
     * Without this, `admin.users.approve` — which the Manager role holds, and which is deliberately separate
     * from `admin.users.manage` — was an unrestricted "disable any account" power: a POST to
     * `/admin/users/{admin}/reject` on the live Administrator locked the mill out of its own admin screens with
     * no in-app way back. Deactivating an existing account is `admin.users.manage` (UserController's is_active),
     * and doing it to an admin needs the admin role (the Task-14 privilege boundary, HANDOFF §7).
     *
     * @throws DomainException when the account has already been decided
     */
    private function assertPending(User $user): void
    {
        if ($user->isPendingApproval()) {
            return;
        }

        throw new DomainException(__(
            $user->isApproved() ? 'approval.errors.already_approved' : 'approval.errors.already_decided'
        ));
    }
}
