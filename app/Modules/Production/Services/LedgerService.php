<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Exceptions\InsufficientQuantity;
use App\Modules\Production\Exceptions\LedgerInvariantViolation;
use App\Modules\Production\Exceptions\LotNotOpen;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotBalance;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Status\LotStatusLabeller;
use App\Modules\Production\Status\StatusStageResolver;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of lot_movements, lot_balances and the lots.*_qty / current_label / stage_id /
 * stage_process_id / last_movement_at columns.
 *
 * Every write runs in a transaction (joining the caller's when there is one), locks the lot row and the lot's
 * balance rows, and leaves balances and totals consistent with the movements when it returns. Balances never
 * go negative: a movement that would overdraw its source position throws InsufficientQuantity; the override
 * path (postWithOverride) posts an explicit `adjustment` into that position first, as spec §5.3 requires.
 *
 * Every read that feeds a write is a locking read (FOR UPDATE). Under InnoDB REPEATABLE READ a plain select
 * inside a transaction returns the snapshot fixed by the transaction's first read, so when a caller opens a
 * transaction, reads something, and then posts, a plain read here would miss rows another connection has
 * committed since - even though the lot row itself is locked. Locking reads always see the latest committed
 * state. The public read-only methods (balances, available, assertInvariant) keep plain reads.
 *
 * Lock order (every Production service): the lot row first, then its balance rows, then its movements, then its
 * route steps. Whoever writes to a lot (this ledger, LotRouteService, LotStateService, LotIntakeService) takes
 * the lot row lock before anything else, so per-lot writers are serialised by that one lock.
 *
 * Quantities are decimal(12,2). Every figure is normalised to a 2-dp string on entry and all arithmetic and
 * comparisons run through bcmath, so no float drift can reach the ledger.
 *
 * @phpstan-type Opts array{source?: Model|null, movedAt?: CarbonInterface|null, userId?: int|null, reason?: string|null, reversalOf?: int|null}
 * @phpstan-type Totals array{stock_qty: numeric-string, issued_qty: numeric-string, wip_qty: numeric-string, finished_qty: numeric-string, dispatched_qty: numeric-string, loss_qty: numeric-string}
 */
final class LedgerService
{
    private const SCALE = 2;

    private const ZERO = '0.00';

    /** Kinds that move fabric on the floor and therefore need an open lot. */
    private const NEEDS_OPEN_LOT = ['issue', 'process', 'reprocess'];

    /**
     * Lot columns derived from the balances (spec §5.3 "Derived lot values"). The ledger also owns
     * `current_label`, `last_movement_at` and — unless PPC has pinned them — `stage_id` /
     * `stage_process_id`; refreshTotals() writes whichever of those it derived on the call.
     */
    private const TOTAL_COLUMNS = ['stock_qty', 'issued_qty', 'wip_qty', 'finished_qty', 'dispatched_qty', 'loss_qty'];

    public function __construct(
        private readonly LotStatusLabeller $labeller,
        private readonly StatusStageResolver $stages,
    ) {}

    /**
     * Post one movement: $qtyOut leaves $from (if any) and $qtyIn arrives at $to (if any).
     *
     * @param  Opts  $opts
     *
     * @throws InsufficientQuantity when $from holds less than $qtyOut
     * @throws LotNotOpen for issue/process/reprocess on a lot whose state is not `open`
     * @throws InvalidArgumentException for a malformed movement (unknown kind, a from/to pair the kind does not allow, negative or non-finite quantities)
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
                $shortfall = bcsub(self::qty($qtyOut), $this->availableQty($locked, $from, true), self::SCALE);
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
     * A reversal is allowed on a held or closed lot (it undoes what was recorded, it records nothing new).
     * Throws InsufficientQuantity when the metres have already moved on, and DomainException when the
     * movement was already reversed (checked under the lot lock; the unique index on reversal_of_id backstops it)
     * or is itself a reversal (undoing an undo is re-posting the original, which the caller does explicitly).
     */
    public function reverse(LotMovement $movement, ?int $userId, ?string $reason = null): LotMovement
    {
        if ($movement->reversal_of_id !== null) {
            throw new DomainException(__('production.errors.cannot_reverse_reversal', ['id' => $movement->id]));
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

    /**
     * Re-derive the totals and the status label without a movement: after a state change (hold / release /
     * cancel / close), or after a route edit moved the next step. The caller's instance gets the new values.
     */
    public function relabel(Lot $lot): void
    {
        DB::transaction(function () use ($lot): void {
            $locked = $this->lock($lot);
            $balances = $this->lockBalances($locked);
            $lastMovedAt = LotMovement::query()->where('lot_id', $locked->getKey())->lockForUpdate()->max('moved_at');
            $this->refreshTotals($locked, $lot, $balances, $lastMovedAt === null ? null : (string) $lastMovedAt);
        });
    }

    /** Recompute balances and totals from the movement rows (repair / backfill). */
    public function rebuild(Lot $lot): void
    {
        DB::transaction(function () use ($lot): void {
            $locked = $this->lock($lot);
            LotBalance::query()->where('lot_id', $locked->getKey())->delete();
            $expected = $this->expectedBalances($locked, true);

            $rows = [];
            foreach ($expected as $position => $qty) {
                $rows[$position] = LotBalance::query()->create(['lot_id' => $locked->getKey(), 'position' => $position, 'qty' => $qty]);
            }

            $lastMovedAt = LotMovement::query()->where('lot_id', $locked->getKey())->lockForUpdate()->max('moved_at');
            $this->refreshTotals($locked, $lot, $rows, $lastMovedAt === null ? null : (string) $lastMovedAt);
        });
    }

    /**
     * Spec §5.3 invariant: per position, balance = Σ qty_into_to − Σ qty_out_of_from over all movements
     * (which implies the whole-lot sum identity), and the denormalised lots.*_qty columns equal the totals
     * derived from those movements (reported as `lots.<column>`). Everything is read from the database in one
     * transaction, so the check sees a consistent snapshot rather than the caller's instance.
     *
     * @throws LedgerInvariantViolation
     */
    public function assertInvariant(Lot $lot): void
    {
        $diff = DB::transaction(function () use ($lot): array {
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

            $expectedTotals = $this->totalsFrom($expected, $this->issuedQty($lot));
            /** @var array<string, string|null> $stored */
            $stored = (array) DB::table('lots')->where('id', $lot->getKey())->first(self::TOTAL_COLUMNS);
            foreach ($expectedTotals as $column => $e) {
                $a = is_numeric($stored[$column] ?? null) ? self::qty((float) $stored[$column]) : self::ZERO;
                if (bccomp($e, $a, self::SCALE) !== 0) {
                    $diff['lots.'.$column] = ['balance' => (float) $a, 'expected' => (float) $e];
                }
            }

            return $diff;
        });

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
            if ($row['reversal_of_id'] === null && in_array($kind, self::NEEDS_OPEN_LOT, true) && $locked->state !== 'open') {
                throw new LotNotOpen($locked);
            }
            $balances = $this->lockBalances($locked);
            if ($row['reversal_of_id'] !== null && LotMovement::query()->where('reversal_of_id', $row['reversal_of_id'])->lockForUpdate()->exists()) {
                throw new DomainException(__('production.errors.already_reversed', ['id' => $row['reversal_of_id']]));
            }

            if ($from !== null) {
                $available = isset($balances[$from->value()]) ? $balances[$from->value()]->qty : self::ZERO;
                if (bccomp($out, $available, self::SCALE) > 0) {
                    throw new InsufficientQuantity($locked, $from, (float) $available, (float) $out);
                }
            }

            try {
                $movement = LotMovement::query()->create($row + [
                    'lot_id' => $locked->getKey(),
                    'kind' => $kind,
                    'from_position' => $from?->value(),
                    'to_position' => $to?->value(),
                    'qty_out_of_from' => $from === null ? self::ZERO : $out,
                    'qty_into_to' => $to === null ? self::ZERO : $in,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // reversal_of_id is the table's only unique column: the check above lost a race it should not be able to lose
                if ($row['reversal_of_id'] !== null) {
                    throw new DomainException(__('production.errors.already_reversed', ['id' => $row['reversal_of_id']]), 0, $e);
                }
                throw $e;
            }

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
        $this->assertShape($kind, $from, $to, $isReversal);

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
            return; // a reversal mirrors its original: it may take 0 back from a total-loss step
        }
        if ($from !== null && $outSign <= 0) {
            throw new InvalidArgumentException('A movement out of a position must take a positive quantity; use an adjustment to add metres.');
        }
    }

    /**
     * The from/to pairs each kind may carry (spec §5.3 "Ledger rules"). A reversal carries the mirrored pair,
     * so its (to, from) is checked against the rules for the original.
     *
     * - import:     none → STOCK
     * - issue:      STOCK → ISSUED
     * - process:    ISSUED|STEP:* → STEP:*|FINISHED (different positions), or in place at a non-terminal
     *               position other than STOCK (an unplanned operation that does not advance the route)
     * - reprocess:  in place at a non-terminal position other than STOCK (FINISHED → FINISHED included)
     * - dispatch:   FINISHED → DISPATCHED
     * - loss:       any non-terminal position → LOSS
     * - adjustment: exactly one side, and it is non-terminal
     */
    private function assertShape(string $kind, ?Position $from, ?Position $to, bool $isReversal): void
    {
        $posted = ($from?->value() ?? 'none').' → '.($to?->value() ?? 'none');
        if ($isReversal) {
            [$from, $to] = [$to, $from];
        }

        $inPlace = fn (): bool => $from !== null && $to !== null && $from->equals($to) && ! $from->isTerminal() && $from->value() !== Position::STOCK;
        $onlySide = ($from === null) !== ($to === null) ? ($from ?? $to) : null;

        $ok = match ($kind) {
            'import' => $from === null && $to?->value() === Position::STOCK,
            'issue' => $from?->value() === Position::STOCK && $to?->value() === Position::ISSUED,
            'process' => ($from !== null && $to !== null && ! $from->equals($to)
                    && ($from->value() === Position::ISSUED || $from->isStep())
                    && ($to->isStep() || $to->value() === Position::FINISHED))
                || $inPlace(),
            'reprocess' => $inPlace(),
            'dispatch' => $from?->value() === Position::FINISHED && $to?->value() === Position::DISPATCHED,
            'loss' => $from !== null && ! $from->isTerminal() && $to?->value() === Position::LOSS,
            'adjustment' => $onlySide !== null && ! $onlySide->isTerminal(),
            default => false,
        };

        if (! $ok) {
            throw new InvalidArgumentException(($isReversal ? 'A reversal of ' : 'A ')."'{$kind}' movement cannot go {$posted}.");
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

    /**
     * @param  bool  $locked  a locking read (only under the lot lock; feeds a write)
     * @return numeric-string
     */
    private function availableQty(Lot $lot, Position $position, bool $locked = false): string
    {
        $query = LotBalance::query()->where('lot_id', $lot->getKey())->where('position', $position->value());
        if ($locked) {
            $query->lockForUpdate();
        }

        // null: the lot has never had metres at this position (the column itself is a non-null decimal)
        return self::decimal($query->value('qty'));
    }

    /**
     * @param  bool  $locked  a locking read (only under the lot lock; feeds a write)
     * @return array<string, numeric-string> position => Σ into − Σ out over the lot's movements
     */
    private function expectedBalances(Lot $lot, bool $locked = false): array
    {
        $expected = [];
        $query = LotMovement::query()->where('lot_id', $lot->getKey())->orderBy('id');
        if ($locked) {
            $query->lockForUpdate();
        }
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
     * Net metres issued to the floor: Σ taken out of STOCK by `issue` movements, less Σ put back by their
     * reversals. Unlike greige − STOCK it is not disturbed by adjustments or losses posted against STOCK.
     *
     * @param  bool  $locked  a locking read (only under the lot lock; feeds a write)
     * @return numeric-string
     */
    private function issuedQty(Lot $lot, bool $locked = false): string
    {
        $issues = LotMovement::query()->where('lot_id', $lot->getKey())->where('kind', 'issue');
        if ($locked) {
            $issues->lockForUpdate();
        }
        $issued = self::decimal((clone $issues)->whereNull('reversal_of_id')->sum('qty_out_of_from'));
        $reversed = self::decimal((clone $issues)->whereNotNull('reversal_of_id')->sum('qty_into_to'));

        return bcsub($issued, $reversed, self::SCALE);
    }

    /**
     * The denormalised totals (spec §5.3 "Derived lot values") for a set of balances.
     *
     * @param  array<string, numeric-string>  $qtyByPosition
     * @param  numeric-string  $issuedQty
     * @return Totals
     */
    private function totalsFrom(array $qtyByPosition, string $issuedQty): array
    {
        $wip = self::ZERO;
        foreach ($qtyByPosition as $position => $qty) {
            if (Position::from($position)->isWip()) {
                $wip = bcadd($wip, $qty, self::SCALE);
            }
        }

        return [
            'stock_qty' => $qtyByPosition[Position::STOCK] ?? self::ZERO,
            'issued_qty' => $issuedQty,
            'wip_qty' => $wip,
            'finished_qty' => $qtyByPosition[Position::FINISHED] ?? self::ZERO,
            'dispatched_qty' => $qtyByPosition[Position::DISPATCHED] ?? self::ZERO,
            'loss_qty' => $qtyByPosition[Position::LOSS] ?? self::ZERO,
        ];
    }

    /**
     * Write the totals and the status label with a query-builder update so the Lot's audit trail records user
     * edits only; these columns are derived from the ledger, which is its own audit trail. The label is derived
     * here, from the balances just written and the locked lot's state, so it can never disagree with them.
     * The caller's instance gets the new values too, so it stays current after the call.
     *
     * The reporting stage and process are resolved from that same label in this one update, so they cannot
     * drift from it either: there is no second writer and no later job to run. The resolver memoises the
     * alias map for the request, so this costs no query per lot. An unmapped status leaves both columns null
     * (the Unmapped Statuses screen reports it) rather than guessing a stage.
     *
     * Unless PPC has pinned the stage by hand (`lots.stage_overridden_at`). Then the two stage columns are
     * left exactly as they are — the label and every quantity are still written — until the override is
     * cleared on the Lots screen, which re-derives them there and then. That screen is the only other writer
     * of those two columns, and only through an audited save with a recorded actor.
     *
     * @param  array<string, LotBalance>  $balances
     */
    private function refreshTotals(Lot $locked, Lot $callerInstance, array $balances, CarbonInterface|string|null $lastMovementAt): void
    {
        $qtyByPosition = array_map(fn (LotBalance $row): string => $row->qty, $balances);
        $label = $this->labeller->label($locked, $qtyByPosition);
        $totals = $this->totalsFrom($qtyByPosition, $this->issuedQty($locked, true))
            + ['current_label' => $label]
            + ($locked->isStageOverridden() ? [] : $this->stages->resolve($label))
            + ['last_movement_at' => $lastMovementAt];

        Lot::query()->whereKey($locked->getKey())->update($totals);

        foreach ([$locked, $callerInstance] as $instance) {
            $instance->forceFill($totals)->syncOriginalAttributes(array_keys($totals));
        }
    }

    /**
     * Normalise a quantity to the ledger's 2-dp string form once, at the boundary.
     *
     * @return numeric-string
     */
    private static function qty(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('A quantity must be a finite number.');
        }

        /** @var numeric-string */
        return number_format(round($value, self::SCALE), self::SCALE, '.', '');
    }

    /**
     * A DECIMAL value as the database returned it (a string on MySQL, which is passed to bcmath untouched;
     * SQLite sums its REAL storage and returns a float, which is rounded once) as a 2-dp string; null → 0.
     *
     * @return numeric-string
     */
    private static function decimal(mixed $value): string
    {
        if (is_float($value)) {
            return self::qty($value);
        }

        return is_numeric($value) ? bcadd((string) $value, self::ZERO, self::SCALE) : self::ZERO;
    }

    private static function currentUserId(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }
}
