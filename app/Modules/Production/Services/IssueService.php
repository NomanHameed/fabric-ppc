<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Activity\Concerns\RecordsUserActions;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Production\Exceptions\InsufficientQuantity;
use App\Modules\Production\Exceptions\LotNotOpen;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Issue to Floor (spec §5.3 "Issue to Floor", §6 workflow 2): STOCK → ISSUED, partial quantities allowed,
 * per lot or every open lot of a PO at once. Nothing is written here except through LedgerService::post().
 */
final class IssueService
{
    use RecordsUserActions;

    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Issue $qty metres of $lot's greige to the floor on $on (today when null), recorded against $user.
     *
     * @throws InsufficientQuantity when STOCK holds less than $qty
     * @throws LotNotOpen when the lot is on hold, closed or cancelled
     */
    public function issue(Lot $lot, float $qty, User $user, ?CarbonInterface $on = null, ?string $remarks = null): LotMovement
    {
        $movement = $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), $qty, $qty, [
            'userId' => $user->id, 'movedAt' => $on ?? now(), 'reason' => $remarks,
        ]);

        // After the commit, and after the bulk path's outer transaction (issueContract) too: the log is not
        // allowed to fail a movement that has already happened, and ActionLogger can only make that promise
        // outside a transaction. Stamped with the issue date, so the action log and the lot timeline read in
        // production order rather than data-entry order.
        DB::afterCommit(fn () => $this->recordAction(
            ActionKeys::LOT_ISSUED,
            $lot,
            ['qty' => round($qty, 2), 'lot' => $lot->lot_no],
            $user,
            $on,
        ));

        return $movement;
    }

    /**
     * Issue the whole remaining STOCK of every open lot on the PO, all or nothing, in one transaction.
     * Held, closed and cancelled lots and lots with nothing in stock are skipped. The lots are read with a
     * locking read in lot-number order, so what is issued is what is in stock now and two bulk issues of
     * the same PO take their lot locks in the same order. Returns the number of lots issued.
     *
     * @throws LotNotOpen|InsufficientQuantity only if a lot changes under the lock (the ledger's own checks)
     */
    public function issueContract(Contract $contract, User $user, ?CarbonInterface $on = null): int
    {
        return DB::transaction(function () use ($contract, $user, $on): int {
            $lots = Lot::query()->where('contract_id', $contract->getKey())
                ->orderBy('lot_no')->orderBy('id')->lockForUpdate()->get();
            $n = 0;
            foreach ($lots as $lot) {
                if ($lot->state !== 'open' || (float) $lot->stock_qty <= 0) {
                    continue;
                }
                $this->issue($lot, (float) $lot->stock_qty, $user, $on);
                $n++;
            }

            return $n;
        }, 3);
    }
}
