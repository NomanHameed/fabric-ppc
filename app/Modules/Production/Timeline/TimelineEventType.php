<?php

namespace App\Modules\Production\Timeline;

/** Every timeline event type, each with a `timeline.events.<type>` line in lang/en and lang/ur. */
final class TimelineEventType
{
    // Derived from lot_movements
    public const LOADED = 'loaded';           // import: greige received into stock

    public const ISSUED = 'issued';           // stock → floor

    public const MOVED = 'moved';             // process between two positions

    public const PROCESSED = 'processed';     // process in place (an operation that does not advance the route)

    public const REPROCESSED = 'reprocessed';

    public const DISPATCHED = 'dispatched';

    public const LOSS = 'loss';

    public const ADJUSTED = 'adjusted';

    public const REVERSED = 'reversed';

    // Derived from the lot's audit trail
    public const STATE_CHANGED = 'state_changed';

    public const COLOUR_STATUS_CHANGED = 'colour_status_changed';

    // Derived from colour_overrides
    public const COLOUR_OVERRIDE_APPROVED = 'colour_override_approved';

    // Derived from user_actions recorded against this lot
    public const ACTION = 'action';

    /** @var list<string> */
    public const ALL = [
        self::LOADED, self::ISSUED, self::MOVED, self::PROCESSED, self::REPROCESSED, self::DISPATCHED,
        self::LOSS, self::ADJUSTED, self::REVERSED, self::STATE_CHANGED, self::COLOUR_STATUS_CHANGED,
        self::COLOUR_OVERRIDE_APPROVED, self::ACTION,
    ];
}
