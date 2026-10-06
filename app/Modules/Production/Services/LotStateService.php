<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Activity\Concerns\RecordsUserActions;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\Production\Models\Lot;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Hold / release / cancel (spec §6 workflow 6; D10 leaves closing to P3). Every transition is a normal audited
 * save of the Lot (who, when, old → new state and reason), attributed to $user in any context, followed by the
 * ledger re-deriving the status label. A held or cancelled lot refuses issue / process / reprocess movements
 * (LedgerService throws LotNotOpen); adjustments, losses, dispatches and reversals stay allowed.
 *
 * Hold and cancel reasons both come from the `hold_reason` lookup list (spec §5.1 seeds no separate cancel list).
 */
final class LotStateService
{
    use RecordsUserActions;

    public const REASON_LIST = 'hold_reason';

    public function __construct(private readonly LedgerService $ledger) {}

    /** @throws DomainException when the lot is not open, or the reason is not an active `hold_reason` code */
    public function hold(Lot $lot, string $reason, User $user): void
    {
        $code = $this->reason($reason);

        $this->transition($lot, $user, from: ['open'], to: 'hold', attrs: ['hold_reason' => $code]);

        $this->record(ActionKeys::LOT_HELD, $lot, ['lot' => $lot->lot_no, 'reason' => $code], $user);
    }

    /** @throws DomainException when the lot is not on hold */
    public function release(Lot $lot, User $user): void
    {
        $this->transition($lot, $user, from: ['hold'], to: 'open', attrs: ['hold_reason' => null]);

        $this->record(ActionKeys::LOT_RELEASED, $lot, ['lot' => $lot->lot_no], $user);
    }

    /**
     * Cancelling is for lots that never went to the floor (or came back off it): metres in WIP or FINISHED must
     * be closed with a loss (P3) instead. The check runs on the locked row, inside the transaction.
     *
     * @throws DomainException when the lot is closed or already cancelled, has metres in WIP or FINISHED, or the reason is unknown
     */
    public function cancel(Lot $lot, string $reason, User $user): void
    {
        $code = $this->reason($reason);

        $this->transition(
            $lot, $user, from: ['open', 'hold'], to: 'cancelled',
            attrs: ['cancel_reason' => $code, 'hold_reason' => null],
            guard: function (Lot $locked): void {
                if (round((float) $locked->wip_qty + (float) $locked->finished_qty, 2) > 0) {
                    throw new DomainException(__('production.errors.cancel_has_wip', ['lot' => $locked->lot_no]));
                }
            },
        );

        $this->record(ActionKeys::LOT_CANCELLED, $lot, ['lot' => $lot->lot_no, 'reason' => $code], $user);
    }

    /**
     * The action-log entry for a transition that has already committed.
     *
     * The `audits` row is the record of *what changed*; this is the record of *what somebody did*, in a
     * sentence a mill supervisor reads, and it is what the action log screen and LotTimelineService's
     * `fromUserActions()` read. Wrapped in afterCommit so that a caller which has wrapped this service in its
     * own transaction still gets the log only once the state change is real (it runs immediately when there is
     * no transaction open).
     *
     * @param  array<string, scalar|null>  $context
     */
    private function record(string $action, Lot $lot, array $context, User $user): void
    {
        DB::afterCommit(fn () => $this->recordAction($action, $lot, $context, $user));
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, string|null>  $attrs
     * @param  (callable(Lot): void)|null  $guard  extra check on the locked row, after the state check
     */
    private function transition(Lot $lot, User $user, array $from, string $to, array $attrs, ?callable $guard = null): void
    {
        ImportCommitService::asAuditUser($user, fn () => DB::transaction(function () use ($lot, $from, $to, $attrs, $guard): void {
            $locked = Lot::query()->whereKey($lot->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->state, $from, true)) {
                throw new DomainException(__('production.errors.invalid_state_change', [
                    'lot' => $locked->lot_no, 'state' => __('production.states.'.$locked->state),
                ]));
            }
            if ($guard !== null) {
                $guard($locked);
            }

            $changes = $attrs + ['state' => $to, 'state_changed_at' => now()];
            $locked->fill($changes)->save(); // audited: who / when / old → new

            // the caller's instance stays current: state from the save, totals and label from the ledger
            $lot->forceFill($locked->only(array_keys($changes)))->syncOriginalAttributes(array_keys($changes));
            $this->ledger->relabel($lot);
        }));
    }

    /** @throws DomainException unless $code is an active value of the `hold_reason` list */
    private function reason(string $code): string
    {
        $known = LookupValue::query()->list(self::REASON_LIST)->where('is_active', true)->where('code', $code)->exists();
        if (! $known) {
            throw new DomainException(__('production.errors.unknown_reason', ['code' => $code]));
        }

        return $code;
    }
}
