<?php

namespace App\Modules\Activity\Support;

/**
 * Every action key the application records. A key is `<area>.<verb-in-past-tense>` and has a matching
 * `activity.actions.<key>` line in lang/en and lang/ur; ActionLogger renders the description from it, so no
 * caller ever passes a bare string.
 */
final class ActionKeys
{
    // Access
    public const USER_REGISTERED = 'user.registered';

    public const USER_APPROVED = 'user.approved';

    public const USER_REJECTED = 'user.rejected';

    public const DEVICE_NAMED = 'device.named';

    // Production
    public const LOT_ISSUED = 'lot.issued';

    public const LOT_MOVED = 'lot.moved';

    public const LOT_HELD = 'lot.held';

    public const LOT_RELEASED = 'lot.released';

    public const LOT_CLOSED = 'lot.closed';

    public const LOT_DISPATCHED = 'lot.dispatched';

    public const COLOUR_APPROVED = 'colour.approved';

    public const COLOUR_REJECTED = 'colour.rejected';

    // Capacity
    public const CAPACITY_ALLOCATED = 'capacity.allocated';

    /** @var list<string> */
    public const ALL = [
        self::USER_REGISTERED, self::USER_APPROVED, self::USER_REJECTED, self::DEVICE_NAMED,
        self::LOT_ISSUED, self::LOT_MOVED, self::LOT_HELD, self::LOT_RELEASED, self::LOT_CLOSED,
        self::LOT_DISPATCHED, self::COLOUR_APPROVED, self::COLOUR_REJECTED, self::CAPACITY_ALLOCATED,
    ];
}
