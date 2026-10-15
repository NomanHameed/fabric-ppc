<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\OracleSync\Models\OracleImportRow;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\ColourOverride;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\LotRouteStep;
use App\Modules\Production\Models\ProductionLine;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Services\LotStateService;
use App\Modules\Production\Services\ProductionSheetService;
use App\Modules\Production\Status\LotStatusLabeller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use OwenIt\Auditing\Models\Audit;

/**
 * Lot Inquiry and PO Inquiry (spec §4.4 screens 3–4, §9 "History" tab), read-only under `inquiry.view`.
 *
 * The lot page always carries the summary (Oracle fields, state, label, process flag, derived quantities), the balances
 * per position, the route timeline and the record position among the lots of the same PO. The heavy tabs — movement
 * history, production lines and the audit history — are optional props: the tab the URL names (`?tab=`) travels with
 * the page, the others only on a partial reload (`router.get(url, {tab}, {only: [tab, 'tab']})`). Each of them is capped
 * at ROW_CAP rows, latest first, and says how many rows there are in all. The History tab needs `audit.view`
 * (spec §8: admin / ppc / manager): without it the `audit` prop is not sent at all and `?tab=audit` falls back to
 * the route tab. Hold / release / cancel stay on LotStateController; the page only shows the buttons when the user
 * has `production.hold`, and the out-links on the Movements / Production tabs only with `production.sheet.view` /
 * `oracle.import.view`.
 *
 * The PO page caps its lots at ROW_CAP as well (`lotsTotal` is the true count, "showing n of m"), but its pinned
 * totals row is summed in the database over *every* lot of the PO, not over the page.
 */
class InquiryController extends Controller
{
    /** Rows a heavy tab shows at most ("showing n of m"). */
    public const ROW_CAP = 200;

    /** @var list<string> */
    private const TABS = ['route', 'movements', 'lines', 'audit'];

    /** @var list<string> the lot columns the PO page totals */
    private const QTY_COLUMNS = ['greige_received_oracle', 'greige_in_stock_oracle', 'qty_issued', 'wip', 'finished', 'dispatch', 'loss_qty'];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly LotRouteService $routes,
        private readonly LotStatusLabeller $labeller,
    ) {}

    public function lot(Request $request, Lot $lot): Response
    {
        $lot->load(['contract.party', 'stage', 'stageOverrider']);
        $balances = $this->ledger->balances($lot);
        $steps = $lot->routeSteps()->with('operation.department')->get();
        $user = $request->user();
        $canAudit = $user->can('audit.view');
        $requested = (string) $request->query('tab', 'route');
        $tab = in_array($requested, self::TABS, true) ? $requested : 'route';
        // ?tab=audit without audit.view falls back to the summary tab; the prop itself is left out below
        if ($tab === 'audit' && ! $canAudit) {
            $tab = 'route';
        }
        // the tab the URL names is part of the page; the others come only on a partial reload
        $optional = fn (string $name, callable $load) => $tab === $name ? $load() : Inertia::optional($load);

        $props = [
            'lot' => $this->lotSummary($lot),
            'siblings' => $this->siblings($lot),
            'positions' => $this->positions($balances, $steps),
            'route' => $this->routeTimeline($lot, $steps, $balances),
            'tab' => $tab,
            'movements' => $optional('movements', fn () => $this->movements($lot)),
            'lines' => $optional('lines', fn () => $this->lines($lot)),
            'holdReasons' => LookupValue::query()->list(LotStateService::REASON_LIST)->get()
                ->map(fn (LookupValue $v): array => ['value' => $v->code, 'label' => $v->getAttribute('label'), 'is_active' => $v->is_active])->values(),
            'colourStatuses' => LookupValue::query()->list(ProductionSheetService::COLOUR_STATUS_LIST)->get()
                ->map(fn (LookupValue $v): array => ['value' => $v->code, 'label' => $v->getAttribute('label')])->values(),
            'canHold' => $user->can('production.hold'),
            'canAudit' => $canAudit,
            // the Movements and Production tabs link out to the sheet and the Oracle import; without the permission
            // the cell stays plain text, so no role is sent to a 403
            'canOpenSheet' => $user->can('production.sheet.view'),
            'canOpenImport' => $user->can('oracle.import.view'),
        ];
        // without audit.view the key is absent altogether, so even a hand-crafted `?tab=audit` partial reload
        // resolves nothing and returns no rows
        if ($canAudit) {
            $props['audit'] = $optional('audit', fn () => $this->audit($lot));
        }

        return Inertia::render('inquiry/LotInquiry', $props);
    }

    public function contract(Contract $contract): Response
    {
        $contract->load('party');
        $total = $contract->lots()->count();
        // the totals row is the whole PO, not the page: summed in the database over every lot of it
        $sums = $contract->lots()->toBase()
            ->selectRaw(implode(', ', array_map(fn (string $c): string => "coalesce(sum({$c}), 0) as {$c}", self::QTY_COLUMNS)))
            ->first();
        $lots = $contract->lots()->with('stage')
            ->orderBy('lot_no_oracle')->orderBy('id')->limit(self::ROW_CAP)->get();

        return Inertia::render('inquiry/PoInquiry', [
            'contract' => [
                'id' => $contract->id, 'po_no' => $contract->po_no, 'party' => $contract->party->name, 'ref_no' => $contract->ref_no,
                'po_date' => $contract->po_date->format('d-m-Y'), 'contract_qty' => (float) $contract->contract_qty,
                'oracle_missing_at' => $contract->oracle_missing_at?->format('d-m-Y'), 'lots_count' => $total,
            ],
            'lots' => $lots->map(fn (Lot $l): array => [
                'id' => $l->id, 'lot_no_oracle' => $l->lot_no_oracle, 'quality' => $l->quality, 'design' => $l->design, 'color_oracle_name' => $l->color_oracle_name,
                'clr_status_oracle' => $l->clr_status_oracle, 'po_type_oracle' => $l->po_type_oracle, 'state' => $l->state, 'status_oracle' => $l->status_oracle,
                'stage_id' => $l->stage_id, 'stage' => $l->stage?->name,
                'process_flag' => $this->labeller->processFlag($l), 'oracle_missing_at' => $l->oracle_missing_at?->format('d-m-Y'),
            ] + $this->quantities($l))->values(),
            'lotsTotal' => $total,
            'totals' => collect(self::QTY_COLUMNS)->mapWithKeys(fn (string $c) => [$c => round((float) ($sums->{$c} ?? 0), 2)]),
        ]);
    }

    // ---- lot page pieces -------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function lotSummary(Lot $lot): array
    {
        return [
            'id' => $lot->id, 'lot_no_oracle' => $lot->lot_no_oracle, 'oracle_line_id' => $lot->oracle_line_id, 'contract_id' => $lot->contract_id,
            'po_no' => $lot->contract->po_no, 'party' => $lot->contract->party->name, 'ref_no' => $lot->contract->ref_no,
            'po_date' => $lot->contract->po_date->format('d-m-Y'), 'contract_qty' => (float) $lot->contract->contract_qty,
            'quality' => $lot->quality, 'gsm' => $lot->gsm, 'design_oracle_id' => $lot->design_oracle_id, 'design' => $lot->design,
            'color_oracle_id' => $lot->color_oracle_id, 'color_oracle_name' => $lot->color_oracle_name, 'clr_status_oracle' => $lot->clr_status_oracle,
            'gw_oracle' => $lot->gw_oracle === null ? null : (float) $lot->gw_oracle, 'f_width' => $lot->f_width === null ? null : (float) $lot->f_width,
            'po_type_oracle' => $lot->po_type_oracle, 'state' => $lot->state, 'hold_reason' => $lot->hold_reason, 'cancel_reason' => $lot->cancel_reason,
            'state_changed_at' => $lot->state_changed_at?->format('d-m-Y H:i'),
            'status_oracle' => $lot->status_oracle, 'process_flag' => $this->labeller->processFlag($lot),
            // The reporting stage beside the raw status (both, never one instead of the other).
            'stage_id' => $lot->stage_id, 'stage' => $lot->stage?->name,
            'stage_overridden' => $lot->isStageOverridden(),
            'stage_overridden_at' => $lot->stage_overridden_at?->format('d-m-Y H:i'),
            'stage_overridden_by' => $lot->stageOverrider?->name,
            'oracle_missing_at' => $lot->oracle_missing_at?->format('d-m-Y'), 'last_movement_at' => $lot->last_movement_at?->format('d-m-Y H:i'),
            'loss_reason' => $lot->loss_reason, 'closed_at' => $lot->closed_at?->format('d-m-Y H:i'), 'remarks' => $lot->remarks,
        ] + $this->quantities($lot);
    }

    /** @return array<string, float> the seven derived quantities (spec §5.3 "Derived lot values") */
    private function quantities(Lot $lot): array
    {
        $out = [];
        foreach (self::QTY_COLUMNS as $column) {
            $out[$column] = (float) $lot->getAttribute($column);
        }

        return $out;
    }

    /**
     * Record n of m among the lots of the same PO (lot_no_oracle order), for Previous / Next on the toolbar.
     *
     * @return array{index: int, count: int, prev_id: int|null, next_id: int|null}
     */
    private function siblings(Lot $lot): array
    {
        $ids = Lot::query()->where('contract_id', $lot->contract_id)->orderBy('lot_no_oracle')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $i = $ids->search($lot->id);
        $i = $i === false ? 0 : (int) $i;

        return ['index' => $i + 1, 'count' => $ids->count(), 'prev_id' => $ids->get($i - 1), 'next_id' => $ids->get($i + 1)];
    }

    /**
     * Metres per position, in the order the positions first appeared on the lot; a STEP position names its step.
     *
     * @param  array<string, float>  $balances
     * @param  Collection<int, LotRouteStep>  $steps
     * @return list<array<string, mixed>>
     */
    private function positions(array $balances, $steps): array
    {
        $out = [];
        foreach ($balances as $position => $qty) {
            $p = Position::from($position);
            $step = $p->isStep() ? $steps->firstWhere('id', $p->stepId()) : null;
            $out[] = [
                'position' => $position, 'kind' => $p->labelKey(), 'qty' => $qty,
                'step_seq' => $step?->seq, 'operation' => $step?->operation->name,
            ];
        }

        return $out;
    }

    /**
     * The route as a timeline: every step with its status, the positions it takes from and lands at, the metres waiting
     * at each of them, and when it was last done (the latest standing `process` movement between those positions).
     *
     * @param  Collection<int, LotRouteStep>  $steps
     * @param  array<string, float>  $balances
     * @return list<array<string, mixed>>
     */
    private function routeTimeline(Lot $lot, $steps, array $balances): array
    {
        $positions = $this->routes->stepPositions($steps);
        $lastDone = [];
        $rows = DB::table('lot_movements')->where('lot_id', $lot->id)->where('kind', 'process')->whereNull('reversal_of_id')
            ->whereNotExists(fn (QueryBuilder $q) => $q->from('lot_movements as r')->whereColumn('r.reversal_of_id', 'lot_movements.id'))
            ->groupBy('from_position', 'to_position')
            ->selectRaw('from_position, to_position, max(moved_at) as last_at')->get();
        foreach ($rows as $r) {
            $lastDone[$r->from_position.'>'.$r->to_position] = $r->last_at;
        }

        return array_values($steps->map(function (LotRouteStep $s) use ($positions, $balances, $lastDone): array {
            [$from, $to] = $positions[$s->id] ?? [null, null];
            $department = $s->operation->department; // Department::name is a locale-aware accessor, so it is read as an attribute
            $last = $from === null || $to === null ? null : ($lastDone[$from->value().'>'.$to->value()] ?? null);

            return [
                'id' => $s->id, 'seq' => $s->seq, 'operation' => $s->operation->name, 'stage_label' => $s->operation->stage_label,
                'department' => $department instanceof Department ? $department->getAttribute('name') : null,
                'status' => $s->status, 'is_added' => $s->is_added,
                'from_position' => $from?->value(), 'to_position' => $to?->value(),
                'available' => $from === null ? 0.0 : (float) ($balances[$from->value()] ?? 0.0),
                'at_position' => $to === null ? 0.0 : (float) ($balances[$to->value()] ?? 0.0),
                'last_done_at' => $last === null ? null : CarbonImmutable::parse((string) $last)->format('d-m-Y H:i'),
            ];
        })->all());
    }

    /**
     * The ledger rows, latest first, capped: kind, from → to, metres out / in, who and when, the reason, the reversal
     * links both ways, and the source (a sheet line with its sheet, an Oracle import row with its import, or nothing).
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private function movements(Lot $lot): array
    {
        $total = LotMovement::query()->where('lot_id', $lot->id)->count();
        $reversedBy = LotMovement::query()->where('lot_id', $lot->id)->whereNotNull('reversal_of_id')->pluck('id', 'reversal_of_id');
        $rows = array_values(LotMovement::query()->where('lot_id', $lot->id)->with(['user', 'source'])
            ->orderByDesc('moved_at')->orderByDesc('id')->limit(self::ROW_CAP)->get()
            ->map(fn (LotMovement $m): array => [
                'id' => $m->id, 'moved_at' => $m->moved_at->format('d-m-Y H:i'), 'kind' => $m->kind,
                'from' => $m->from_position, 'to' => $m->to_position,
                'qty_out' => (float) $m->qty_out_of_from, 'qty_in' => (float) $m->qty_into_to,
                'user' => $m->user?->name, 'reason' => $m->reason,
                'reversal_of_id' => $m->reversal_of_id, 'reversed_by_id' => $reversedBy->get($m->id),
            ] + $this->source($m))->all());

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array{source_type: string|null, source_id: int|null, sheet_id: int|null, import_id: int|null}
     */
    private function source(LotMovement $m): array
    {
        $source = $m->source;

        return [
            'source_type' => match (true) {
                $m->source_type === null => null,
                $m->source_type === (new ProductionLine)->getMorphClass() => 'line',
                $m->source_type === (new OracleImportRow)->getMorphClass() => 'import',
                default => class_basename($m->source_type),
            },
            'source_id' => $m->source_id === null ? null : (int) $m->source_id,
            'sheet_id' => $source instanceof ProductionLine ? $source->production_sheet_id : null,
            'import_id' => $source instanceof OracleImportRow ? (int) $source->getAttribute('oracle_import_id') : null,
        ];
    }

    /**
     * The lot's production lines, newest sheet first, capped, with a link to the sheet.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private function lines(Lot $lot): array
    {
        $query = ProductionLine::query()->where('production_lines.lot_id', $lot->id);
        $total = (clone $query)->count();
        $rows = array_values($query->with(['sheet.machine', 'sheet.shift', 'operation', 'routeStep', 'colourOverride'])
            ->join('production_sheets', 'production_sheets.id', '=', 'production_lines.production_sheet_id')
            ->select('production_lines.*')
            ->orderByDesc('production_sheets.sheet_date')->orderByDesc('production_lines.id')->limit(self::ROW_CAP)->get()
            ->map(fn (ProductionLine $l): array => [
                'id' => $l->id, 'sheet_id' => $l->production_sheet_id, 'date' => $l->sheet->sheet_date->format('d-m-Y'),
                'shift' => $l->sheet->shift->code, 'machine' => $l->sheet->machine->name, 'operation' => $l->operation->name,
                'step_seq' => $l->routeStep?->seq, 'batch_no' => $l->batch_no, 'is_reprocess' => $l->is_reprocess, 'reprocess_reason' => $l->reprocess_reason,
                'qty_in' => (float) $l->qty_in, 'qty_out' => (float) $l->qty_out, 'output_type' => $l->output_type,
                'g_width' => $l->g_width === null ? null : (float) $l->g_width, 'f_width' => $l->f_width === null ? null : (float) $l->f_width,
                'colour_override' => $l->colourOverride?->reason, 'remarks' => $l->remarks,
            ])->all());

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The audit trail (spec §9): the lot's own rows plus those of its route steps, production lines and colour overrides,
     * latest first, capped. Limitation: the related ids are plucked from the rows that are still there, so once a
     * production line, route step or colour override is hard-deleted (deleting its sheet, for instance) its audit rows
     * — the `created` and the `deleted` alike — drop out of this tab. The Movements tab is the surviving trail for that
     * work: the posting and its reversal both stay in the ledger.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    private function audit(Lot $lot): array
    {
        $stepIds = LotRouteStep::query()->where('lot_id', $lot->id)->pluck('id');
        $lineIds = ProductionLine::query()->where('lot_id', $lot->id)->pluck('id');
        $overrideIds = ColourOverride::query()->where('lot_id', $lot->id)->pluck('id');
        $query = Audit::query()->where(function (Builder $q) use ($lot, $stepIds, $lineIds, $overrideIds): void {
            $q->where(fn (Builder $w) => $w->where('auditable_type', $lot->getMorphClass())->where('auditable_id', $lot->id))
                ->orWhere(fn (Builder $w) => $w->where('auditable_type', (new LotRouteStep)->getMorphClass())->whereIn('auditable_id', $stepIds))
                ->orWhere(fn (Builder $w) => $w->where('auditable_type', (new ProductionLine)->getMorphClass())->whereIn('auditable_id', $lineIds))
                ->orWhere(fn (Builder $w) => $w->where('auditable_type', (new ColourOverride)->getMorphClass())->whereIn('auditable_id', $overrideIds));
        });
        $total = (clone $query)->count();
        $audits = $query->orderByDesc('id')->limit(self::ROW_CAP)->get();
        // the acting users in one query (Audit::user() is a morphTo the analyser cannot see; every audit here is by an App user)
        $users = User::query()->whereIn('id', $audits->where('user_type', (new User)->getMorphClass())->pluck('user_id')->filter()->unique())->pluck('name', 'id');
        $rows = array_values($audits->map(fn (Audit $a): array => [
            'id' => $a->id, 'at' => $a->created_at?->format('d-m-Y H:i'),
            'user' => $a->getAttribute('user_type') === (new User)->getMorphClass() ? $users->get($a->getAttribute('user_id')) : null, 'event' => $a->event,
            'model' => class_basename($a->auditable_type), 'record_id' => (int) $a->auditable_id,
            'old' => (array) ($a->old_values ?? []), 'new' => (array) ($a->new_values ?? []),
        ])->all());

        return ['rows' => $rows, 'total' => $total];
    }
}
