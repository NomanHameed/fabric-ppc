<?php

namespace App\Modules\Foundation\Access;

/**
 * Signup moderation states. `pending` is the database default: an account that reaches the users table without
 * anyone deciding otherwise is refused everything (EnsureUserIsApproved), so a missed code path fails closed.
 */
final class Approval
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** @var list<string> */
    public const ALL = [self::PENDING, self::APPROVED, self::REJECTED];

    /** The permission a moderator needs to approve or reject a signup. */
    public const PERMISSION = 'admin.users.approve';
}
