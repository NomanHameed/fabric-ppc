<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Production\Exceptions\ColourNotApproved;
use App\Modules\Production\Exceptions\InsufficientQuantity;
use App\Modules\Production\Exceptions\LotNotOpen;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotBalance;
use App\Modules\Production\Models\LotRouteStep;
use App\Modules\Production\Models\ProductionLine;
use App\Modules\Production\Models\ProductionSheet;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Production sheet save / delete (spec §5.3 ledger rules, §5.4 colour gate, §6 workflow 3), one transaction per call.
 *
 * Every line is posted through LedgerService, from LotRouteService::previousPosition() to targetPosition() of its
 * route step (that exact pair is what drives the step statuses):
 * - a planned line advances the route (`process`, previous → target);
 * - a reprocess line keeps the metres where they are (`reprocess`, in place at the step's target, or at the largest
 *   floor position when no step is named);
 * - an unplanned line (operation not on the route) is `process` in place at the largest floor position, step null.
 * An edited or removed line is never corrected by touching its ledger rows: its movements are reversed (latest first,
 * so an override adjustment is undone after the movement it enabled) and the new version is posted afresh. Removed
 * and changed lines are unposted from the last line of the sheet upwards before anything is re-posted, so a later
 * line that consumed an earlier line's output does not block the edit.
 *
 * Lock order (every Production service): the lot row first. A save locks the sheet row, then every lot it touches
 * in id order, before reading anything that decides a posting, so two saves on the same lot are serialised and the
 * second one sees the first one's rows. Callers get ValidationException with `lines.<row>.<field>` keys for every
 * refusal the user can act on (insufficient metres carry the available figure; the colour gate names the lot,
 * status and operation), so the controller never has to map a domain exception itself.
 *
 * @phpstan-type Header array{sheet_date: string, shift_id: int, department_id: int, machine_id: int, downtime_minutes?: int, remarks?: string|null}
 * @phpstan-type LinePayload array{id?: int|null, batch_no?: string|null, lot_id: int, lot_route_step_id?: int|null, operation_id: int, is_reprocess?: bool,
 *     reprocess_reason?: string|null, qty_in: float|int|string, qty_out: float|int|string, output_type?: string|null, g_width?: float|null, f_width?: float|null,
 *     remarks?: string|null, override_reason?: string|null, colour_override_reason?: string|null}
 * @phpstan-type Buckets array{fresh: float, reprocess: float, stretching: float, total: float}
 */
final class ProductionSheetService
{
    /** Departments whose operations always need an approved colour (spec §5.4), plus any operation with finish_type. */
    private const GATED_DEPARTMENTS = ['JIGGER_DYEING', 'PRINTING'];

    public const REPROCESS_REASON_LIST = 'reprocess_reason';

    public const COLOUR_STATUS_LIST = 'colour_status';

    /** @var list<string>|null colour_status codes flagged approved, cached per save */
    private ?array $approvedCodes = null;

    public function __construct(private readonly LedgerService $ledger, private readonly LotRouteService $routes) {}

    /**
     * Create ($sheet null) or update a sheet with exactly the given lines: lines missing from $lines are removed,
     * lines with an `id` are edited (re-posted only when something that reached the ledger changed), the rest are new.
     *
     * @param  Header  $header
     * @param  list<LinePayload>  $lines
     *
     * @throws ValidationException on every refusal (`machine_id`, `lines.<row>.<field>`, or `lines`)
     */
    public function save(?ProductionSheet $sheet, array $header, array $lines, User $user): ProductionSheet
    {
        $sheetId = $sheet?->getKey();
        $lines = array_values($lines);
        $this->approvedCodes = null;

        return DB::transaction(function () use ($sheetId, $header, $lines, $user): ProductionSheet {
            // a fresh, row-locked instance per attempt (a deadlock retry re-runs this closure from the top)
            $sheet = $sheetId === null
                ? new ProductionSheet(['entered_by' => $user->getKey()])
                : ProductionSheet::query()->whereKey($sheetId)->lockForUpdate()->firstOrFail();
            $existing = $sheet->exists ? $sheet->lines()->get()->keyBy('id') : new EloquentCollection;
            $lots = $this->lockLots(array_merge(array_column($lines, 'lot_id'), $existing->pluck('lot_id')->all()));

            $this->assertMachineInDepartment((int) $header['machine_id'], (int) $header['department_id']);
            $sheet->fill($header)->save();

            // 1. Which existing line each payload row is, and whether it has to go through the ledger again.
            $rows = [];
            $keptIds = [];
            foreach ($lines as $i => $payload) {
                $line = null;
                if (isset($payload['id'])) {
                    $line = $existing->get((int) $payload['id'])
                        ?? throw ValidationException::withMessages(["lines.{$i}.id" => __('production.errors.line_not_on_sheet')]);
                    $keptIds[] = $line->id;
                }
                $rows[$i] = ['line' => $line, 'changed' => $line === null || ! $this->unchanged($line, $payload, (int) $sheet->department_id)];
            }
            $removed = $existing->reject(fn (ProductionLine $l) => in_array($l->id, $keptIds, true));

            // 2. Unpost removed and changed lines, last line of the sheet first.
            $unpost = $removed->map(fn (ProductionLine $l) => ['line' => $l, 'row' => null])->values()->all();
            foreach ($rows as $i => $row) {
                if ($row['line'] !== null && $row['changed']) {
                    $unpost[] = ['line' => $row['line'], 'row' => $i];
                }
            }
            usort($unpost, fn (array $a, array $b): int => $b['line']->line_no <=> $a['line']->line_no);
            foreach ($unpost as $u) {
                $this->unpost($u['line'], $lots[$u['line']->lot_id], $user, $u['row']);
            }
            foreach ($removed as $line) {
                $line->colourOverride()->first()?->delete();
                $line->delete();
            }

            // 3. Post the new and changed lines in sheet order; renumber the unchanged ones in place.
            foreach ($rows as $i => $row) {
                $payload = $lines[$i];
                if (! $row['changed'] && $row['line'] !== null) {
                    $row['line']->update(['line_no' => $i + 1, 'batch_no' => $payload['batch_no'] ?? null, 'remarks' => $payload['remarks'] ?? null]);

                    continue;
                }
                $this->post($sheet, $row['line'], $payload, $i, $user, $lots);
            }

            return $sheet->load('lines');
        }, 3);
    }

    /**
     * Remove the sheet: every line is unposted (reversals, last line first) and deleted, then the sheet row.
     *
     * @throws ValidationException (`lines`) when a line's metres have already moved on
     */
    public function delete(ProductionSheet $sheet, User $user): void
    {
        $sheetId = $sheet->getKey();
        DB::transaction(function () use ($sheetId, $user): void {
            $sheet = ProductionSheet::query()->whereKey($sheetId)->lockForUpdate()->firstOrFail();
            $lines = $sheet->lines()->get();
            $lots = $this->lockLots($lines->pluck('lot_id')->all());
            foreach ($lines->sortByDesc('line_no') as $line) {
                $this->unpost($line, $lots[$line->lot_id], $user, null);
            }
            foreach ($lines as $line) {
                $line->colourOverride()->first()?->delete();
                $line->delete();
            }
            $sheet->delete();
        }, 3);
    }

    /**
     * Sheet footer (spec §4.4 screen 5, §6.3.6): fresh / reprocess / stretching / total metres out, for the whole
     * sheet and per output type. A reprocess line counts as reprocess whatever its operation; a stretch operation
     * counts as stretching, not fresh (ruling).
     *
     * @return array{all: Buckets, dyed: Buckets, print: Buckets, other: Buckets}
     */
    public function totals(ProductionSheet $sheet): array
    {
        return self::totalsOf($sheet->lines()->with('operation')->get());
    }

    /**
     * @param  iterable<ProductionLine>  $lines  with their operation loaded
     * @return array{all: Buckets, dyed: Buckets, print: Buckets, other: Buckets}
     */
    public static function totalsOf(iterable $lines): array
    {
        $buckets = ['fresh' => 0.0, 'reprocess' => 0.0, 'stretching' => 0.0, 'total' => 0.0];
        $out = ['all' => $buckets, 'dyed' => $buckets, 'print' => $buckets, 'other' => $buckets];
        foreach ($lines as $line) {
            $bucket = $line->is_reprocess ? 'reprocess' : ($line->operation->is_stretch ? 'stretching' : 'fresh');
            $type = $line->output_type ?? 'other';
            foreach (['all', $type] as $k) {
                $out[$k][$bucket] = round($out[$k][$bucket] + (float) $line->qty_out, 2);
                $out[$k]['total'] = round($out[$k]['total'] + (float) $line->qty_out, 2);
            }
        }

        return $out;
    }

    /**
     * What the sheet screen needs once a lot is picked on a line: the lot's identity, balances, the default route
     * step for the sheet's department (null: the operation will be recorded as unplanned, in place), the colour
     * gate flag, and every route step with the metres waiting at its source position.
     *
     * @return array<string, mixed>
     */
    public function lotContext(Lot $lot, int $departmentId): array
    {
        $lot->loadMissing(['contract.party', 'quality']);
        $balances = $this->ledger->balances($lot);
        $steps = $lot->routeSteps()->with('operation.department')->get();
        $default = $this->routes->nextPendingStep($lot, $departmentId);
        $floor = $this->floorPosition($balances);

        return [
            'lot_id' => $lot->id, 'lot_no' => $lot->lot_no, 'po_no' => $lot->contract->po_no, 'party' => $lot->contract->party->name,
            'quality' => $lot->quality?->description, 'design' => $lot->design, 'colour' => $lot->colour, 'po_type' => $lot->po_type,
            'state' => $lot->state, 'current_label' => $lot->current_label,
            'colour_status' => $lot->colour_status, 'colour_approved' => $this->colourApproved($lot),
            'balances' => $balances,
            'g_width' => $lot->g_width === null ? null : (float) $lot->g_width, 'f_width' => $lot->f_width === null ? null : (float) $lot->f_width,
            'default_step_id' => $default?->id,
            'next_step_id' => $this->routes->nextPendingStep($lot)?->id,
            'floor_position' => $floor?->value(),
            'floor_available' => $floor === null ? 0.0 : (float) ($balances[$floor->value()] ?? 0.0),
            'steps' => $steps->map(function (LotRouteStep $s) use ($lot): array {
                $from = $s->isActive() ? $this->routes->previousPosition($s) : null;

                return [
                    'id' => $s->id, 'seq' => $s->seq, 'operation_id' => $s->operation_id, 'operation' => $s->operation->name,
                    'department_id' => $s->operation->department_id, 'department' => $s->operation->department->getAttribute('name'),
                    'status' => $s->status, 'is_added' => $s->is_added,
                    'from_position' => $from?->value(), 'to_position' => $s->isActive() ? $this->routes->targetPosition($s)->value() : null,
                    'available' => $from === null ? 0.0 : $this->ledger->available($lot, $from),
                ];
            })->values()->all(),
        ];
    }

    // ---- posting ---------------------------------------------------------------------------------------------

    /**
     * @param  LinePayload  $p
     * @param  array<int, Lot>  $lots  the row-locked lots of this save, by id
     */
    private function post(ProductionSheet $sheet, ?ProductionLine $line, array $p, int $i, User $user, array $lots): ProductionLine
    {
        $lot = $lots[(int) $p['lot_id']];
        $operation = Operation::query()->with('department')->findOrFail((int) $p['operation_id']);
        if ($operation->department_id !== $sheet->department_id) {
            throw ValidationException::withMessages(["lines.{$i}.operation_id" => __('production.errors.operation_department')]);
        }
        $isReprocess = (bool) ($p['is_reprocess'] ?? false) || $operation->kind === 'reprocess';
        $reprocessReason = $isReprocess ? $this->reprocessReason($p['reprocess_reason'] ?? null, $i) : null;
        [$step, $from, $to] = $this->resolve($lot, $operation, isset($p['lot_route_step_id']) ? (int) $p['lot_route_step_id'] : null, $sheet->department_id, $isReprocess, $i);

        $attrs = [
            'production_sheet_id' => $sheet->id, 'line_no' => $i + 1, 'batch_no' => $p['batch_no'] ?? null, 'lot_id' => $lot->id,
            'lot_route_step_id' => $step?->id, 'operation_id' => $operation->id, 'is_reprocess' => $isReprocess,
            'reprocess_reason' => $reprocessReason,
            'qty_in' => round((float) $p['qty_in'], 2), 'qty_out' => round((float) $p['qty_out'], 2),
            'output_type' => $p['output_type'] ?? $operation->finish_type,
            'g_width' => $p['g_width'] ?? $lot->g_width, 'f_width' => $p['f_width'] ?? $lot->f_width, 'remarks' => $p['remarks'] ?? null,
        ];
        $line = $line === null ? ProductionLine::query()->create($attrs) : tap($line)->update($attrs);

        $this->applyColourGate($line, $lot, $operation, $p, $i, $user);

        $kind = $isReprocess ? 'reprocess' : 'process';
        // the sheet date at noon: a lot's movement history then sorts by production day (the shift is on the sheet row)
        $opts = ['source' => $line, 'movedAt' => $sheet->sheet_date->setTime(12, 0), 'userId' => $user->getKey(), 'reason' => $reprocessReason];
        $override = trim((string) ($p['override_reason'] ?? ''));
        if ($override !== '' && ! $user->can('production.override')) {
            throw ValidationException::withMessages(["lines.{$i}.override_reason" => __('production.errors.override_not_allowed')]);
        }
        try {
            if ($override !== '') {
                $this->ledger->postWithOverride($lot, $kind, $from, $to, (float) $p['qty_in'], (float) $p['qty_out'], $override, $opts);
            } else {
                $this->ledger->post($lot, $kind, $from, $to, (float) $p['qty_in'], (float) $p['qty_out'], $opts);
            }
        } catch (InsufficientQuantity $e) {
            throw ValidationException::withMessages(["lines.{$i}.qty_in" => $e->getMessage()]);
        } catch (LotNotOpen $e) {
            throw ValidationException::withMessages(["lines.{$i}.lot_id" => $e->getMessage()]);
        }
        $this->routes->refreshStatuses($lot);

        return $line;
    }

    /**
     * Reverse this line's standing movements, latest first (the process/reprocess row before the override adjustment
     * that made room for it), and bring the step statuses back. The line row is kept for re-posting or deleted by the
     * caller; the reversal rows keep the line as their source either way.
     *
     * @param  int|null  $row  the payload row being edited, or null when the line is being removed
     */
    private function unpost(ProductionLine $line, Lot $lot, User $user, ?int $row): void
    {
        $movements = $line->movements()->get();
        $reversed = $movements->pluck('reversal_of_id')->filter()->all();
        foreach ($movements->reverse() as $m) {
            if ($m->reversal_of_id !== null || in_array($m->id, $reversed, true)) {
                continue; // a reversal, or already reversed
            }
            try {
                $this->ledger->reverse($m, $user->getKey(), __('production.reasons.line_edited'));
            } catch (DomainException $e) { // InsufficientQuantity (metres moved on) or already reversed
                throw ValidationException::withMessages([
                    $row === null ? 'lines' : "lines.{$row}.qty_in" => __('production.errors.cannot_unpost', ['lot' => $lot->lot_no, 'line' => $line->line_no]),
                ]);
            }
        }
        $this->routes->refreshStatuses($lot);
    }

    /**
     * Where the line acts on the route, and the from/to positions of its movement.
     *
     * - explicit step: as picked (it must be an active step of this lot);
     * - planned line: the first pending/partial step whose operation is the line's, else the last step of that
     *   operation (a repeat of finished work: the ledger then reports what is left at its source), else the
     *   operation is not on the route → unplanned, in place at the largest floor position, step null;
     * - reprocess line: at the step's target when a step is given, else in place at the largest floor position,
     *   recorded against the step that lands there when it belongs to the sheet's department.
     *
     * @return array{0: LotRouteStep|null, 1: Position, 2: Position}
     */
    private function resolve(Lot $lot, Operation $operation, ?int $stepId, int $departmentId, bool $isReprocess, int $i): array
    {
        $steps = $lot->routeSteps()->with('operation')->lockForUpdate()->get();
        $step = null;
        if ($stepId !== null) {
            $step = $steps->firstWhere('id', $stepId);
            if ($step === null || ! $step->isActive()) {
                throw ValidationException::withMessages(["lines.{$i}.lot_route_step_id" => __('production.errors.step_not_on_route')]);
            }
        } elseif (! $isReprocess) {
            $ofOperation = $steps->filter(fn (LotRouteStep $s) => $s->isActive() && $s->operation_id === $operation->id);
            $step = $ofOperation->first(fn (LotRouteStep $s) => in_array($s->status, ['pending', 'partial'], true)) ?? $ofOperation->last();
        }

        if ($step !== null) {
            $target = $this->routes->targetPosition($step);

            return $isReprocess ? [$step, $target, $target] : [$step, $this->routes->previousPosition($step), $target];
        }

        $position = $this->floorPosition($this->lockedBalances($lot))
            ?? throw ValidationException::withMessages(["lines.{$i}.qty_in" => __('production.errors.nothing_on_floor', ['lot' => $lot->lot_no])]);
        if ($isReprocess) {
            $active = $steps->filter(fn (LotRouteStep $s) => $s->isActive())->values();
            $at = $position->isStep() ? $active->firstWhere('id', $position->stepId()) : ($position->value() === Position::FINISHED ? $active->last() : null);
            $step = $at !== null && $at->operation->department_id === $departmentId ? $at : null;
        }

        return [$step, $position, $position];
    }

    /**
     * Colour gate (spec §5.4): a gated operation on a lot whose colour is not approved needs a reason from a user
     * with `colour.override.approve`, logged as a colour_overrides row on the line. An override already logged on
     * the line for the same lot stands through edits; it is dropped when the gate no longer applies.
     *
     * @param  LinePayload  $p
     */
    private function applyColourGate(ProductionLine $line, Lot $lot, Operation $operation, array $p, int $i, User $user): void
    {
        $existing = $line->colourOverride()->first();
        if (! $this->gateApplies($operation) || $this->colourApproved($lot)) {
            $existing?->delete();

            return;
        }
        $reason = trim((string) ($p['colour_override_reason'] ?? ''));
        if ($reason === '') {
            if ($existing !== null && $existing->lot_id === $lot->id) {
                return;
            }
            throw ValidationException::withMessages(["lines.{$i}.colour_override_reason" => (new ColourNotApproved($lot, $operation))->getMessage()]);
        }
        if (! $user->can('colour.override.approve')) {
            throw ValidationException::withMessages(["lines.{$i}.colour_override_reason" => __('production.errors.colour_override_not_allowed')]);
        }
        $attrs = ['lot_id' => $lot->id, 'reason' => $reason, 'approved_by' => $user->getKey(), 'approved_at' => now()];
        if ($existing === null) {
            $line->colourOverride()->create($attrs);
        } else {
            $existing->update($attrs);
        }
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    /**
     * Nothing about the line that reached the ledger (or the colour gate) differs from the payload: it is left as
     * posted. Batch no. and remarks are updated without a re-post; a payload field left null means "as saved".
     *
     * @param  LinePayload  $p
     */
    private function unchanged(ProductionLine $line, array $p, int $departmentId): bool
    {
        $same = fn (mixed $a, mixed $b): bool => round((float) $a, 2) === round((float) $b, 2);
        if ($line->lot_id !== (int) $p['lot_id'] || $line->operation_id !== (int) $p['operation_id'] || $line->operation->department_id !== $departmentId) {
            return false;
        }
        $isReprocess = (bool) ($p['is_reprocess'] ?? false) || $line->operation->kind === 'reprocess';

        return (! isset($p['lot_route_step_id']) || $line->lot_route_step_id === (int) $p['lot_route_step_id'])
            && $line->is_reprocess === $isReprocess
            && $same($line->qty_in, $p['qty_in']) && $same($line->qty_out, $p['qty_out'])
            && $line->output_type === ($p['output_type'] ?? $line->output_type)
            && ($line->g_width === null ? ! isset($p['g_width']) : $same($line->g_width, $p['g_width'] ?? $line->g_width))
            && ($line->f_width === null ? ! isset($p['f_width']) : $same($line->f_width, $p['f_width'] ?? $line->f_width))
            && (! $isReprocess || $line->reprocess_reason === ($p['reprocess_reason'] ?? null));
    }

    /**
     * Row-lock every lot the save touches, in id order (so two saves sharing lots take the locks in the same
     * order), before any read that decides a posting.
     *
     * @param  list<int|string>  $ids
     * @return array<int, Lot> by id
     */
    private function lockLots(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        return Lot::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();
    }

    /** @return array<string, float> position => qty, a locking read (feeds the position choice of a posting) */
    private function lockedBalances(Lot $lot): array
    {
        $out = [];
        foreach (LotBalance::query()->where('lot_id', $lot->getKey())->orderBy('id')->lockForUpdate()->get(['position', 'qty']) as $row) {
            $out[$row->position] = (float) $row->qty;
        }

        return $out;
    }

    /**
     * Where an unplanned or step-less reprocess line acts (spec §5.3 "Unplanned operation"): the non-terminal
     * position other than STOCK holding the most metres, the earliest one on a tie; null when nothing is on the floor.
     *
     * @param  array<string, float>  $balances
     */
    private function floorPosition(array $balances): ?Position
    {
        $candidates = array_filter($balances, fn (float $qty, string $pos) => $qty > 0 && $pos !== Position::STOCK && ! Position::from($pos)->isTerminal(), ARRAY_FILTER_USE_BOTH);
        if ($candidates === []) {
            return null;
        }
        arsort($candidates);

        return Position::from((string) array_key_first($candidates));
    }

    private function gateApplies(Operation $operation): bool
    {
        return $operation->finish_type !== null || in_array($operation->department->code, self::GATED_DEPARTMENTS, true);
    }

    /** Spec §5.4: approved means the lot's colour_status code carries the lookup's `approved` flag. */
    private function colourApproved(Lot $lot): bool
    {
        if ($lot->colour_status === null) {
            return false;
        }
        $this->approvedCodes ??= LookupValue::query()->list(self::COLOUR_STATUS_LIST)->get()
            ->filter(fn (LookupValue $v) => (bool) ($v->meta['approved'] ?? false))->pluck('code')->values()->all();

        return in_array($lot->colour_status, $this->approvedCodes, true);
    }

    /** A reprocess line needs an active `reprocess_reason` code. */
    private function reprocessReason(?string $code, int $i): string
    {
        $code = trim((string) $code);
        if ($code === '') {
            throw ValidationException::withMessages(["lines.{$i}.reprocess_reason" => __('production.errors.reprocess_reason_required')]);
        }
        if (! LookupValue::query()->list(self::REPROCESS_REASON_LIST)->where('is_active', true)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(["lines.{$i}.reprocess_reason" => __('production.errors.unknown_reason', ['code' => $code])]);
        }

        return $code;
    }

    private function assertMachineInDepartment(int $machineId, int $departmentId): void
    {
        if (! Machine::query()->whereKey($machineId)->whereHas('departments', fn ($q) => $q->whereKey($departmentId))->exists()) {
            throw ValidationException::withMessages(['machine_id' => __('production.errors.machine_department')]);
        }
    }
}
