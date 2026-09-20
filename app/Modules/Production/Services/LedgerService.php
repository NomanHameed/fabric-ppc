<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Exceptions\InsufficientQuantity;
use App\Modules\Production\Exceptions\LedgerInvariantViolation;
use App\Modules\Production\Exceptions\LotNotOpen;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotBalance;
use App\Modules\Production\Models\LotMovement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of lot_movements, lot_balances and the lots.*_qty / current_label / last_movement_at columns.
 *
 * Every write runs in a transaction (joining the caller's when there is one), locks the lot row and the lot's
 * balance rows, and leaves balances and totals consistent with the movements when it returns. Balances never
 * go negative: a movement that would overdraw its source position throws InsufficientQuantity; the override
 * path (postWithOverride) posts an explicit `adjustment` into that position first, as spec §5.3 requires.
 *
 * Quantities are decimal(12,2). Every figure is normalised to a 2-dp string on entry and all arithmetic and
 * comparisons run through bcmath, so no float drift can reach the ledger.
 *
 * @phpstan-type Opts array{source?: Model|null, movedAt?: CarbonInterface|null, userId?: int|null, reason?: string|null, reversalOf?: int|null}
 */
final class LedgerService
{
    private const SCALE = 2;

    private const ZERO = '0.00';

    /** Kinds that move fabric on the floor and therefore need an open lot. */
    private const NEEDS_OPEN_LOT = ['issue', 'process', 'reprocess'];

    /** Lot columns derived from the balances (spec §5.3 "Derived lot values"). */
    private const DERIVED_COLUMNS = ['stock_qty', 'issued_qty', 'wip_qty', 'finished_qty', 'dispatched_qty', 'loss_qty', 'last_movement_at'];

    /**
     * Post one movement: $qtyOut leaves $from (if any) and $qtyIn arrives at $to (if any).
     *
     * @param  Opts  $opts
     *
     * @throws InsufficientQuantity when $from holds less than $qtyOut
     * @throws LotNotOpen for issue/process/reprocess on a lot whose state is not `open`
     * @throws InvalidArgumentException for a malformed movement (unknown kind, no positions, negative quantities, leaving a terminal position)
     */
    public function post(Lot $lot, string $kind, ?Position $from, ?Position $to, float $qtyOut, float $qtyIn, array $opts = []): LotMovement
    {
        $out = self::qty($qtyOut);
        $in = self::qty($qtyIn);
        $this->validate($kind, $from, $to, $out, $in, ($opts['reversalOf'] ?? null) !== null);

        $source = $opts['source'] ?? null;

        return $this->write($lot, $kind, $from, $to, $out, $in, [
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'moved_at' => $opts['movedAt'] ?? now(),
            'user_id' => $opts['userId'] ?? self::currentUserId(),
            'reason' => $opts['reason'] ?? null,
            'reversal_of_id' => $opts['reversalOf'] ?? null,
        ]);
    }

    /**
     * Post with a PPC/Manager override: when $from holds less than $qtyOut, first post an `adjustment`
     * for the shortfall into $from (with the reason), then the movement itself — both in one transaction.
     * The caller checks the `production.override` permission.
     *
     * @param  Opts  $opts
     * @return array{0: LotMovement|null, 1: LotMovement} [adjustment or null, movement]
     */
    public function postWithOverride(Lot $lot, string $kind, ?Position $from, ?Position $to, float $qtyOut, float $qtyIn, string $reason, array $opts = []): array
    {
        $opts = ['reason' => $reason] + $opts;

        return DB::transaction(function () use ($lot, $kind, $from, $to, $qtyOut, $qtyIn, $opts): array {
            $adjustment = null;
            if ($from !== null) {
                $locked = $this->lock($lot);
                $shortfall = bcsub(self::qty($qtyOut), $this->availableQty($locked, $from), self::SCALE);
                if (bccomp($shortfall, self::ZERO, self::SCALE) > 0) {
                    $adjustment = $this->post($lot, 'adjustment', null, $from, 0, (float) $shortfall, $opts);
                }
            }

            return [$adjustment, $this->post($lot, $kind, $from, $to, $qtyOut, $qtyIn, $opts)];
        });
    }

    /**
     * Undo a movement with a new row (never an update or delete): from/to swapped, quantities swapped,
     * same kind, reversal_of_id set, the original's source kept even if that model is gone by now.
     * Throws InsufficientQuantity when the metres have already moved on.
     */
    public function reverse(LotMovement $movement, ?int $userId, ?string $reason = null): LotMovement
    {
        if (LotMovement::query()->where('reversal_of_id', $movement->getKey())->exists()) {
            throw new InvalidArgumentException("Movement #{$movement->getKey()} has already been reversed.");
        }

        $from = $movement->toPosition();
        $to = $movement->fromPosition();
        $out = self::qty((float) $movement->qty_into_to);
        $in = self::qty((float) $movement->qty_out_of_from);
        $this->validate($movement->kind, $from, $to, $out, $in, true);

        return $this->write($movement->lot, $movement->kind, $from, $to, $out, $in, [
            'source_type' => $movement->source_type,
            'source_id' => $movement->source_id,
            'moved_at' => now(),
            'user_id' => $userId,
            'reason' => $reason,
            'reversal_of_id' => $movement->id,
        ]);
    }

    /** @return array<string, float> position => qty, in the order the positions first appeared on the lot */
    public function balances(Lot $lot): array
    {
        $result = [];
        foreach (LotBalance::query()->where('lot_id', $lot->getKey())->orderBy('id')->get(['position', 'qty']) as $row) {
            $result[$row->position] = (float) $row->qty;
        }

        return $result;
    }

    /** Metres currently at $position (0 when the lot has never had any there). */
    public function available(Lot $lot, Position $position): float
    {
        return (float) $this->availableQty($lot, $position);
    }

    /** Recompute balances and totals from the movement rows (repair / backfill). */
    public function rebuild(Lot $lot): void
    {
        DB::transaction(function () use ($lot): void {
            $locked = $this->lock($lot);
            $expected = $this->expectedBalances($locked);

            LotBalance::query()->where('lot_id', $locked->getKey())->delete();
            $rows = [];
            foreach ($expected as $position => $qty) {
                $rows[$position] = LotBalance::query()->create(['lot_id' => $locked->getKey(), 'position' => $position, 'qty' => $qty]);
            }

            $lastMovedAt = LotMovement::query()->where('lot_id', $locked->getKey())->max('moved_at');
            $this->refreshTotals($locked, $lot, $rows, $lastMovedAt === null ? null : (string) $lastMovedAt);
        });
    }

    /**
     * Spec §5.3 invariant: per position, balance = Σ qty_into_to − Σ qty_out_of_from over all movements
     * (which implies the whole-lot sum identity).
     *
     * @throws LedgerInvariantViolation
     */
    public function assertInvariant(Lot $lot): void
    {
        $expected = $this->expectedBalances($lot);
        $actual = [];
        foreach (LotBalance::query()->where('lot_id', $lot->getKey())->get(['position', 'qty']) as $row) {
            $actual[$row->position] = $row->qty;
        }

        $diff = [];
        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $position) {
            $e = $expected[$position] ?? self::ZERO;
            $a = $actual[$position] ?? self::ZERO;
            if (bccomp($e, $a, self::SCALE) !== 0) {
                $diff[$position] = ['balance' => (float) $a, 'expected' => (float) $e];
            }
        }
        if ($diff !== []) {
            throw new LedgerInvariantViolation($lot, $diff);
        }
    }

    // ---- internals -------------------------------------------------------------------------------------------

    /**
     * The single write path: lock, check, insert the row, move the balances, refresh the totals.
     *
     * @param  numeric-string  $out
     * @param  numeric-string  $in
     * @param  array{source_type: string|null, source_id: int|string|null, moved_at: CarbonInterface, user_id: int|null, reason: string|null, reversal_of_id: int|null}  $row
     */
    private function write(Lot $lot, string $kind, ?Position $from, ?Position $to, string $out, string $in, array $row): LotMovement
    {
        return DB::transaction(function () use ($lot, $kind, $from, $to, $out, $in, $row): LotMovement {
            $locked = $this->lock($lot);
            if (in_array($kind, self::NEEDS_OPEN_LOT, true) && $locked->state !== 'open') {
                throw new LotNotOpen($locked);
            }

            $balances = $this->lockBalances($locked);
            if ($from !== null) {
                $available = isset($balances[$from->value()]) ? $balances[$from->value()]->qty : self::ZERO;
                if (bccomp($out, $available, self::SCALE) > 0) {
                    throw new InsufficientQuantity($locked, $from, (float) $available, (float) $out);
                }
            }

            $movement = LotMovement::query()->create($row + [
                'lot_id' => $locked->getKey(),
                'kind' => $kind,
                'from_position' => $from?->value(),
                'to_position' => $to?->value(),
                'qty_out_of_from' => $from === null ? self::ZERO : $out,
                'qty_into_to' => $to === null ? self::ZERO : $in,
            ]);

            if ($from !== null) {
                $this->applyDelta($locked, $balances, $from, bcsub(self::ZERO, $out, self::SCALE));
            }
            if ($to !== null) {
                $this->applyDelta($locked, $balances, $to, $in);
            }

            $lastMovedAt = $locked->last_movement_at === null || $movement->moved_at->greaterThan($locked->last_movement_at)
                ? $movement->moved_at
                : $locked->last_movement_at;
            $this->refreshTotals($locked, $lot, $balances, $lastMovedAt);

            return $movement;
        });
    }

    /**
     * @param  numeric-string  $out
     * @param  numeric-string  $in
     */
    private function validate(string $kind, ?Position $from, ?Position $to, string $out, string $in, bool $isReversal): void
    {
        if (! in_array($kind, LotMovement::KINDS, true)) {
            throw new InvalidArgumentException("Unknown movement kind '{$kind}'.");
        }
        if ($from === null && $to === null) {
            throw new InvalidArgumentException('A movement needs a from or a to position.');
        }
        $outSign = bccomp($out, self::ZERO, self::SCALE);
        $inSign = bccomp($in, self::ZERO, self::SCALE);
        if ($outSign < 0 || $inSign < 0) {
            throw new InvalidArgumentException('Quantities cannot be negative.');
        }
        if ($outSign === 0 && $inSign === 0) {
            throw new InvalidArgumentException('A movement needs a positive quantity.');
        }
        if (($from !== null && $to === null && $outSign <= 0) || ($to !== null && $from === null && $inSign <= 0)) {
            throw new InvalidArgumentException('A one-sided movement needs a positive quantity.');
        }
        if ($isReversal) {
            return; // a reversal mirrors its original: it may re-enter from a terminal position or take 0 back from a total-loss step
        }
        if ($from !== null && $outSign <= 0) {
            throw new InvalidArgumentException('A movement out of a position must take a positive quantity; use an adjustment to add metres.');
        }
        if ($from?->isTerminal()) {
            throw new InvalidArgumentException('Metres cannot leave a terminal position except by reversal.');
        }
    }

    /** A fresh, row-locked copy of the lot; the caller's instance is left alone until the totals are synced back. */
    private function lock(Lot $lot): Lot
    {
        return Lot::query()->whereKey($lot->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @return array<string, LotBalance> position => row, every balance row of the lot locked for update */
    private function lockBalances(Lot $locked): array
    {
        $rows = [];
        foreach (LotBalance::query()->where('lot_id', $locked->getKey())->orderBy('id')->lockForUpdate()->get() as $row) {
            $rows[$row->position] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, LotBalance>  $balances  updated in place (a new row is added when the position is new)
     * @param  numeric-string  $delta  signed
     */
    private function applyDelta(Lot $locked, array &$balances, Position $position, string $delta): void
    {
        $row = $balances[$position->value()]
            ??= LotBalance::query()->create(['lot_id' => $locked->getKey(), 'position' => $position->value(), 'qty' => self::ZERO]);
        $row->qty = bcadd($row->qty, $delta, self::SCALE);
        $row->save();
    }

    /** @return numeric-string */
    private function availableQty(Lot $lot, Position $position): string
    {
        $qty = LotBalance::query()->where('lot_id', $lot->getKey())->where('position', $position->value())->value('qty');

        // null: the lot has never had metres at this position (the column itself is a non-null decimal)
        return is_numeric($qty) ? bcadd((string) $qty, self::ZERO, self::SCALE) : self::ZERO;
    }

    /** @return array<string, numeric-string> position => Σ into − Σ out over the lot's movements */
    private function expectedBalances(Lot $lot): array
    {
        $expected = [];
        $query = LotMovement::query()->where('lot_id', $lot->getKey())->orderBy('id');
        foreach ($query->cursor() as $m) {
            if ($m->from_position !== null) {
                $expected[$m->from_position] = bcsub($expected[$m->from_position] ?? self::ZERO, $m->qty_out_of_from, self::SCALE);
            }
            if ($m->to_position !== null) {
                $expected[$m->to_position] = bcadd($expected[$m->to_position] ?? self::ZERO, $m->qty_into_to, self::SCALE);
            }
        }

        return $expected;
    }

    /**
     * Denormalised totals (spec §5.3 "Derived lot values"), written with a query-builder update so the
     * Lot's audit trail records user edits only; these columns are derived from the ledger, which is its own
     * audit trail. The caller's instance gets the new values too, so it stays current after the call.
     *
     * @param  array<string, LotBalance>  $balances
     */
    private function refreshTotals(Lot $locked, Lot $callerInstance, array $balances, CarbonInterface|string|null $lastMovementAt): void
    {
        $qtyAt = fn (string $position): string => isset($balances[$position]) ? $balances[$position]->qty : self::ZERO;

        $stock = $qtyAt(Position::STOCK);
        $wip = self::ZERO;
        foreach ($balances as $position => $row) {
            if (Position::from($position)->isWip()) {
                $wip = bcadd($wip, $row->qty, self::SCALE);
            }
        }

        $totals = [
            'stock_qty' => $stock,
            'issued_qty' => bcsub(self::qty((float) $locked->greige_qty), $stock, self::SCALE),
            'wip_qty' => $wip,
            'finished_qty' => $qtyAt(Position::FINISHED),
            'dispatched_qty' => $qtyAt(Position::DISPATCHED),
            'loss_qty' => $qtyAt(Position::LOSS),
            'last_movement_at' => $lastMovementAt,
            // current_label: Task 6 (LotStatusLabeller)
        ];

        Lot::query()->whereKey($locked->getKey())->update($totals);

        foreach ([$locked, $callerInstance] as $instance) {
            $instance->forceFill($totals)->syncOriginalAttributes(self::DERIVED_COLUMNS);
        }
    }

    /**
     * Normalise a quantity to the ledger's 2-dp string form once, at the boundary.
     *
     * @return numeric-string
     */
    private static function qty(float $value): string
    {
        /** @var numeric-string */
        return number_format(round($value, self::SCALE), self::SCALE, '.', '');
    }

    private static function currentUserId(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }
}
