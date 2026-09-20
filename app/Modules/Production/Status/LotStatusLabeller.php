<?php

namespace App\Modules\Production\Status;

use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\LotRouteStep;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * lots.current_label, from the first rule that applies (spec §5.3 "Current status label"), and the report's
 * Pending / Complete process flag. The label texts are the tracker's own status codes (they print verbatim in
 * the Month Wise Fabric Report), so they are not translated.
 *
 * LedgerService owns the column: label() is called from its total recompute, under the lot lock, with the
 * balances it has just written, so the label can never disagree with the balances. The reads here therefore
 * feed a write and are locking reads (see LedgerService on REPEATABLE READ snapshots). No constructor
 * dependencies: LotRouteService depends on LedgerService, which depends on this class.
 */
final class LotStatusLabeller
{
    public const CANCELLED = 'CANCELLED/';

    public const HOLD = 'HOLD/';

    public const CLOSED = 'CLOSED/';

    public const DISPATCHED = 'DISPATCHED/';

    public const READY_FOR_INSPECTION = 'RF-INSP/';

    /** P3 (inspection) switches rule 5 to this once an inspection is recorded. */
    public const READY_FOR_DISPATCH = 'RF-DISP';

    /** Fallback for metres on the floor when the lot has no route step to name the next stage. */
    public const WIP = 'WIP/';

    public const FLAG_PENDING = 'pending';

    public const FLAG_COMPLETE = 'complete';

    /**
     * @param  Lot  $lot  the row-locked instance, so `state` is current
     * @param  array<string, float|numeric-string>  $balances  position => qty (never negative: the ledger refuses overdraws)
     */
    public function label(Lot $lot, array $balances): ?string
    {
        if ($lot->state === 'cancelled') {
            return self::CANCELLED;                                                        // rule 1
        }
        if ($lot->state === 'hold') {
            return self::HOLD;                                                             // rule 1
        }

        $stock = (float) ($balances[Position::STOCK] ?? 0.0);
        $finished = (float) ($balances[Position::FINISHED] ?? 0.0);
        $dispatched = (float) ($balances[Position::DISPATCHED] ?? 0.0);
        $wip = [];
        foreach ($balances as $position => $qty) {
            if ((float) $qty > 0 && Position::from($position)->isWip()) {
                $wip[$position] = (float) $qty;
            }
        }

        if ($lot->state === 'closed') {
            return $dispatched > 0 ? self::DISPATCHED : self::CLOSED;                      // rule 2
        }
        if ($dispatched > 0 && ($finished > 0 || $wip !== [])) {                           // rule 3
            $last = $this->lastDispatchAt($lot);

            return $last === null ? self::DISPATCHED : 'DISP '.$last->format('d-m-y').'/';
        }
        if ($wip !== []) {                                                                 // rule 4
            arsort($wip);
            $largest = Position::from((string) array_key_first($wip));

            return $this->nextStepAfter($lot, $largest)?->operation->stage_label ?? self::WIP;
        }
        if ($finished > 0 && $stock <= 0) {                                                // rule 5 (RF-DISP once P3 records an inspection)
            return self::READY_FOR_INSPECTION;
        }

        return null;                                                                       // rule 6: everything still in STOCK
    }

    /**
     * The report's Pending / Complete column (spec §5.3 "Process flag"): Complete when the lot is closed or
     * when STOCK + WIP + FINISHED = 0, from the lot's derived totals.
     *
     * @return self::FLAG_PENDING|self::FLAG_COMPLETE
     */
    public function processFlag(Lot $lot): string
    {
        if ($lot->state === 'closed') {
            return self::FLAG_COMPLETE;
        }

        return round((float) $lot->stock_qty + (float) $lot->wip_qty + (float) $lot->finished_qty, 2) <= 0
            ? self::FLAG_COMPLETE
            : self::FLAG_PENDING;
    }

    /**
     * moved_at of the last dispatch that still stands: reversals are not dispatches, and a reversed dispatch
     * no longer counts. Null only when the DISPATCHED balance came from somewhere other than a dispatch.
     */
    private function lastDispatchAt(Lot $lot): ?CarbonImmutable
    {
        $last = LotMovement::query()
            ->where('lot_id', $lot->getKey())
            ->where('kind', 'dispatch')
            ->whereNull('reversal_of_id')
            ->whereNotExists(fn (Builder $q) => $q->from('lot_movements as r')->whereColumn('r.reversal_of_id', 'lot_movements.id'))
            ->lockForUpdate()
            ->max('moved_at');

        return $last === null ? null : CarbonImmutable::parse((string) $last);
    }

    /** The first non-skipped route step after $position (ISSUED → the first step; STEP:<id> → the step after it). */
    private function nextStepAfter(Lot $lot, Position $position): ?LotRouteStep
    {
        $steps = LotRouteStep::query()
            ->where('lot_id', $lot->getKey())
            ->where('status', '!=', 'skipped')
            ->orderBy('seq')
            ->lockForUpdate()
            ->with('operation')
            ->get();
        if (! $position->isStep()) {
            return $steps->first();
        }
        $current = $steps->firstWhere('id', $position->stepId());
        if ($current === null) {
            return null;
        }

        return $steps->first(fn (LotRouteStep $s) => $s->seq > $current->seq);
    }
}
