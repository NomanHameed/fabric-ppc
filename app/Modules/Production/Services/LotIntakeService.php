<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What Production does when Oracle hands over a lot: route from the template, greige into STOCK
 * (spec §5.3 "Import of a new lot"). Called synchronously inside the import commit transaction, and by
 * production:backfill-ledger for lots that predate the ledger.
 */
final class LotIntakeService
{
    public function __construct(private readonly LotRouteService $routes, private readonly LedgerService $ledger) {}

    /**
     * Idempotent: a lot that already has an `import` movement is left alone (its route is still assigned if missing).
     * A lot with no greige yet gets its route but no movement (the ledger refuses a zero-quantity movement); the
     * movement is posted once a later export brings the quantity, via adjustGreige().
     *
     * The lot row is locked first, so two callers receiving the same lot at once (an import commit and the
     * backfill command, say) run one after the other and the second one sees the first one's movement.
     *
     * @return LotMovement|null the import movement posted, or null when nothing was posted
     */
    public function receive(Lot $lot, ?int $userId, ?Model $source = null): ?LotMovement
    {
        return DB::transaction(function () use ($lot, $userId, $source): ?LotMovement {
            Lot::query()->whereKey($lot->getKey())->lockForUpdate()->first();
            $this->routes->assign($lot);
            if ((float) $lot->greige_received_oracle <= 0 || $this->received($lot)) {
                return null;
            }

            return $this->ledger->post($lot, 'import', null, Position::stock(), 0, (float) $lot->greige_received_oracle, [
                'userId' => $userId, 'source' => $source, 'movedAt' => $lot->created_at,
            ]);
        });
    }

    /**
     * A later export changed greige_received_oracle (already saved on $lot): post the difference as an adjustment in STOCK
     * (spec §5.3). A decrease takes metres out of STOCK and can never overdraw it: the preview flags such rows
     * (`greige_below_issued`) before the commit gets here. A lot the ledger has never seen is received instead.
     *
     * @return LotMovement|null the adjustment (or, for a lot new to the ledger, its import movement), null when nothing changed
     */
    public function adjustGreige(Lot $lot, float $old, float $new, ?int $userId, ?Model $source = null): ?LotMovement
    {
        return DB::transaction(function () use ($lot, $old, $new, $userId, $source): ?LotMovement {
            if (! $this->received($lot)) {
                return $this->receive($lot, $userId, $source);
            }
            $diff = round($new - $old, 2);
            if ($diff == 0) {
                return null;
            }
            $opts = ['userId' => $userId, 'source' => $source,
                'reason' => __('production.reasons.greige_changed', ['old' => number_format($old, 2), 'new' => number_format($new, 2)])];

            return $diff > 0
                ? $this->ledger->post($lot, 'adjustment', null, Position::stock(), 0, $diff, $opts)
                : $this->ledger->post($lot, 'adjustment', Position::stock(), null, -$diff, 0, $opts);
        });
    }

    /** A locking read: it feeds a write, and a plain read would return the transaction's snapshot (see LedgerService). */
    private function received(Lot $lot): bool
    {
        return $lot->movements()->where('kind', 'import')->lockForUpdate()->exists();
    }
}
