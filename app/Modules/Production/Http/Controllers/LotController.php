<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Query\QueryCriteria;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LotStateService;
use App\Modules\Production\Services\ProductionSheetService;
use App\Modules\Production\Status\LotStatusLabeller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Lots screen (spec §4.4 screen 3, §4.2 query mode): one Oracle-Forms record per lot — a grid of the
 * matching lots and a single-block form for the selected one — under `inquiry.view`.
 *
 * Everything Oracle owns (`Lot::ORACLE_FIELDS`) and everything the ledger derives (`stock_qty` … `loss_qty`,
 * `current_label`, `last_movement_at`) is **read-only here**: the import is the only writer of the first set and
 * `LedgerService` of the second. The screen edits exactly two app-owned fields, each behind its own permission:
 * `colour_status` with `colour.edit` (validated against the active `colour_status` lookup list) and `remarks`
 * with `production.route.manage`. Hold / release / cancel stay on `LotStateController` (`production.hold`).
 *
 * Query mode runs through `QueryCriteria` over the lot and its joined PO, party and quality, so `%` wildcards and
 * the `>`/`<`/`a..b` range forms work on the PO number and the PO date as they do on every other Forms screen.
 * With no criteria the screen opens on the open work (`open` + `hold`), Oracle-Forms style. The grid is capped at
 * ROW_CAP rows with the true match count beside it ("showing n of m"), as the inquiry screens are.
 */
class LotController extends Controller
{
    /** Rows the grid shows at most ("showing n of m"); the count beside it is the true number of matches. */
    public const ROW_CAP = 200;

    public function __construct(private readonly LotStatusLabeller $labeller) {}

    public function index(Request $request): Response
    {
        $criteria = (array) $request->query('q', []);
        $query = Lot::query()->with(['contract.party', 'quality'])
            ->join('contracts', 'contracts.id', '=', 'lots.contract_id')
            ->join('parties', 'parties.id', '=', 'contracts.party_id')
            ->join('qualities', 'qualities.id', '=', 'lots.quality_id')
            ->select('lots.*');
        QueryCriteria::apply($query, $criteria, self::CRITERIA);
        // Only criteria that actually narrow anything count: an empty box the user left blank (`q[lot_no]=`)
        // is no criterion, so the screen still opens on the open work rather than on every lot ever imported.
        $effective = array_filter($criteria, fn ($v): bool => ! is_array($v) && trim((string) $v) !== '');
        if ($effective === []) {
            $query->whereIn('lots.state', ['open', 'hold']); // default query: the open work
        }
        $total = (clone $query)->count();
        $lots = $query->orderByDesc('contracts.po_date')->orderBy('contracts.po_no')->orderBy('lots.lot_no')->orderBy('lots.id')
            ->limit(self::ROW_CAP)->get();
        $user = $request->user();

        return Inertia::render('production/Lots', [
            'lots' => $lots->map(fn (Lot $l): array => $this->row($l))->values(),
            'lotsTotal' => $total,
            'colourStatuses' => LookupValue::query()->list(ProductionSheetService::COLOUR_STATUS_LIST)->where('is_active', true)->get()
                ->map(fn (LookupValue $v): array => [
                    'value' => $v->code, 'label' => $v->getAttribute('label'), 'approved' => (bool) ($v->meta['approved'] ?? false),
                ])->values(),
            'holdReasons' => LookupValue::query()->list(LotStateService::REASON_LIST)->where('is_active', true)->get()
                ->map(fn (LookupValue $v): array => ['value' => $v->code, 'label' => $v->getAttribute('label')])->values(),
            'can' => [
                'colour' => $user->can('colour.edit'),
                'remarks' => $user->can('production.route.manage'),
                'hold' => $user->can('production.hold'),
                'route' => $user->can('production.route.manage'),
            ],
            'criteria' => (object) $criteria,
        ]);
    }

    /**
     * The two app-owned fields, each behind its own permission. A field the user may not write has no rule and is
     * never read off the request, so an Oracle-owned field (`Lot::ORACLE_FIELDS`), a derived quantity or
     * `current_label` sent by hand is ignored rather than written — and a viewer, who may write neither, gets 403
     * before any validation runs.
     */
    public function update(Request $request, Lot $lot): RedirectResponse
    {
        $user = $request->user();
        $rules = [];
        if ($user->can('colour.edit')) {
            // an unchanged code stays valid even if the lookup value has since been deactivated (the same
            // allowance ProductionSheetService makes for the approval gate: the lot was set under it)
            $rules['colour_status'] = $request->input('colour_status') === $lot->colour_status
                ? ['nullable', 'string', 'max:40']
                : ['nullable', 'string', Rule::exists('lookup_values', 'code')
                    ->where('list_key', ProductionSheetService::COLOUR_STATUS_LIST)->where('is_active', true)];
        }
        if ($user->can('production.route.manage')) {
            $rules['remarks'] = ['nullable', 'string', 'max:1000'];
        }
        abort_if($rules === [], 403);

        $data = $request->validate($rules);
        $attrs = [];
        foreach (array_keys($rules) as $field) {
            if ($request->has($field)) {
                $attrs[$field] = $data[$field] ?? null;
            }
        }
        // the user may write something, but sent none of it (only fields that are not theirs to change)
        abort_if($attrs === [], 403);

        $lot->fill($attrs)->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);
        Inertia::flash('selectedId', $lot->id);

        return back();
    }

    /** The eleven query-mode fields (spec §4.2), over the lot and its joined PO, party and quality. */
    private const CRITERIA = [
        'lot_no' => ['column' => 'lots.lot_no', 'type' => 'text'],
        'po_no' => ['column' => 'contracts.po_no', 'type' => 'number'],
        'party' => ['column' => 'parties.name', 'type' => 'text'],
        'quality' => ['column' => 'qualities.description', 'type' => 'text'],
        'design' => ['column' => 'lots.design', 'type' => 'text'],
        'colour' => ['column' => 'lots.colour', 'type' => 'text'],
        'colour_status' => ['column' => 'lots.colour_status', 'type' => 'text'],
        'po_type' => ['column' => 'lots.po_type', 'type' => 'text'],
        'state' => ['column' => 'lots.state', 'type' => 'text'],
        'current_label' => ['column' => 'lots.current_label', 'type' => 'text'],
        'po_date' => ['column' => 'contracts.po_date', 'type' => 'date'],
    ];

    /**
     * One grid/form record: the Oracle fields the screen shows, the PO header, the state (with the reason and
     * the moment it changed) and label, and the seven derived quantities (spec §5.3 "Derived lot values").
     * `oracle_line_id`, `design_code` and `colour_code` are deliberately left out: nothing on this screen shows
     * them, and the Lot Inquiry is where the Oracle identifiers belong.
     *
     * @return array<string, mixed>
     */
    private function row(Lot $lot): array
    {
        return [
            'id' => $lot->id, 'lot_no' => $lot->lot_no,
            'contract_id' => $lot->contract_id, 'po_no' => $lot->contract->po_no, 'party' => $lot->contract->party->name,
            'ref_no' => $lot->contract->ref_no, 'po_date' => $lot->contract->po_date->format('d-m-Y'),
            'quality' => $lot->quality->description, 'gsm' => $lot->gsm, 'design' => $lot->design,
            'colour' => $lot->colour, 'colour_status' => $lot->colour_status,
            'g_width' => $lot->g_width === null ? null : (float) $lot->g_width,
            'f_width' => $lot->f_width === null ? null : (float) $lot->f_width,
            'po_type' => $lot->po_type, 'state' => $lot->state, 'hold_reason' => $lot->hold_reason, 'cancel_reason' => $lot->cancel_reason,
            'state_changed_at' => $lot->state_changed_at?->format('d-m-Y H:i'),
            'current_label' => $lot->current_label, 'process_flag' => $this->labeller->processFlag($lot),
            'greige_qty' => (float) $lot->greige_qty, 'stock_qty' => (float) $lot->stock_qty, 'issued_qty' => (float) $lot->issued_qty,
            'wip_qty' => (float) $lot->wip_qty, 'finished_qty' => (float) $lot->finished_qty,
            'dispatched_qty' => (float) $lot->dispatched_qty, 'loss_qty' => (float) $lot->loss_qty,
            'last_movement_at' => $lot->last_movement_at?->format('d-m-Y H:i'),
            'oracle_missing_at' => $lot->oracle_missing_at?->format('d-m-Y'), 'remarks' => $lot->remarks,
        ];
    }
}
