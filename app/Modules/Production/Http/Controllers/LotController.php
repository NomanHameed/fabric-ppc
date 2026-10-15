<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Activity\Concerns\RecordsUserActions;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Query\QueryCriteria;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LotStateService;
use App\Modules\Production\Services\ProductionAnalyticsService;
use App\Modules\Production\Services\ProductionSheetService;
use App\Modules\Production\Status\LotStatusLabeller;
use App\Modules\Production\Status\StatusStageResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Lots screen (spec §4.4 screen 3, §4.2 query mode): one Oracle-Forms record per lot — a grid of the
 * matching lots and a single-block form for the selected one — under `inquiry.view`.
 *
 * Everything Oracle owns (`Lot::ORACLE_FIELDS`) and everything the ledger derives (`greige_in_stock_oracle` … `loss_qty`,
 * `status_oracle`, `last_movement_at`) is **read-only here**: the import is the only writer of the first set and
 * `LedgerService` of the second. The screen edits exactly two app-owned fields, each behind its own permission:
 * `clr_status_oracle` with `colour.edit` (validated against the active `clr_status_oracle` lookup list) and `remarks`
 * with `production.route.manage`. Hold / release / cancel stay on `LotStateController` (`production.hold`).
 *
 * The one thing the screen may write about the reporting stage is an **override**: `production.route.manage`
 * can pin a lot's stage by hand (`overrideStage`) and clear the pin again (`clearStageOverride`), both
 * audited on the Lot itself. While a lot is pinned the ledger leaves `stage_id` alone; clearing re-derives it
 * from the lot's current status on the spot. Neither action is reachable through `update()`: that method's
 * rules never mention the stage columns, so no payload shape can write them there.
 *
 * Query mode runs through `QueryCriteria` over the lot and its joined PO, party and quality, so `%` wildcards and
 * the `>`/`<`/`a..b` range forms work on the PO number and the PO date as they do on every other Forms screen.
 * With no criteria the screen opens on the open work (`open` + `hold`), Oracle-Forms style. The grid is capped at
 * ROW_CAP rows with the true match count beside it ("showing n of m"), as the inquiry screens are.
 *
 * The grid has two **selectable shapes** (`?shape=nested|flat`), and only those two: an unknown value is a
 * 422, the same as on the fabric report, rather than a silent fallback to the app's own columns.
 * `nested` is the original `LotScreenRow`.
 * `flat` adds the Oracle-shaped payload assembled by `ProductionAnalyticsService::flatLots()` — the mill's own
 * field names over the same unchanged columns — as the extra `flatLots` prop. Additive, both ways: `lots` is
 * always sent, so the form blocks and every other consumer are untouched, and the criteria the screen ran
 * narrow both shapes through the same `ProductionAnalyticsService::LOT_CRITERIA` map.
 */
class LotController extends Controller
{
    use RecordsUserActions;

    /** Rows the grid shows at most ("showing n of m"); the count beside it is the true number of matches. */
    public const ROW_CAP = 200;

    /** The two grid shapes the screen can show: the nested `LotScreenRow`, or the flat Oracle-shaped payload. */
    public const SHAPES = ['nested', 'flat'];

    public function __construct(
        private readonly LotStatusLabeller $labeller,
        private readonly StatusStageResolver $stages,
        private readonly ProductionAnalyticsService $analytics,
    ) {}

    public function index(Request $request): Response
    {
        $criteria = (array) $request->query('q', []);
        // An unknown shape is a 422, not a fallback to `nested`. The same ruling as
        // `FabricReportController`: a screen that quietly drew different columns than the URL asked for
        // looks like the flat payload is broken, and a bookmarked typo would never be noticed.
        $shape = $request->validate([
            'shape' => ['nullable', 'string', 'in:'.implode(',', self::SHAPES)],
        ])['shape'] ?? 'nested';
        $query = Lot::query()->with(['contract.party', 'stage', 'stageOverrider'])
            ->join('contracts', 'contracts.id', '=', 'lots.contract_id')
            ->join('parties', 'parties.id', '=', 'contracts.party_id')
            ->join('qualities', 'qualities.id', '=', 'lots.quality_id')
            // Left join: a lot whose status nothing maps has no stage, and an inner join would drop it from
            // the screen entirely.
            ->leftJoin('stages', 'stages.id', '=', 'lots.stage_id')
            ->select('lots.*');
        QueryCriteria::apply($query, $criteria, ProductionAnalyticsService::LOT_CRITERIA);
        // Only criteria that actually narrow anything count: an empty box the user left blank (`q[lot_no_oracle]=`)
        // is no criterion, so the screen still opens on the open work rather than on every lot ever imported.
        $effective = array_filter($criteria, fn ($v): bool => ! is_array($v) && trim((string) $v) !== '');
        $states = $effective === [] ? ['open', 'hold'] : null;
        if ($states !== null) {
            $query->whereIn('lots.state', $states); // default query: the open work
        }
        $total = (clone $query)->count();
        $lots = $query->orderByDesc('contracts.po_date')->orderBy('contracts.po_no')->orderBy('lots.lot_no_oracle')->orderBy('lots.id')
            ->limit(self::ROW_CAP)->get();
        $user = $request->user();

        // The flat, Oracle-shaped payload travels beside the nested rows, never instead of them: the form
        // blocks, the stage override and every other consumer of `lots` keep reading `LotScreenRow`. It is
        // sent eagerly only for the shape the URL asked for; the other one is an optional prop, so switching
        // shapes is a partial reload and the default visit pays for one grid, not two.
        $flat = fn (): array => $this->analytics->flatLots($criteria, $states, self::ROW_CAP);

        return Inertia::render('production/Lots', [
            'lots' => $lots->map(fn (Lot $l): array => $this->row($l))->values(),
            'lotsTotal' => $total,
            'shape' => $shape,
            'flatLots' => $shape === 'flat' ? $flat() : Inertia::optional($flat),
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
                // The same group that edits routes may pin a lot's reporting stage by hand.
                'stage' => $user->can('production.route.manage'),
            ],
            'criteria' => (object) $criteria,
        ]);
    }

    /**
     * The two app-owned fields, each behind its own permission. A field the user may not write has no rule and is
     * never read off the request, so an Oracle-owned field (`Lot::ORACLE_FIELDS`), a derived quantity or
     * `status_oracle` sent by hand is ignored rather than written — and a viewer, who may write neither, gets 403
     * before any validation runs.
     */
    public function update(Request $request, Lot $lot): RedirectResponse
    {
        $user = $request->user();
        $rules = [];
        if ($user->can('colour.edit')) {
            // an unchanged code stays valid even if the lookup value has since been deactivated (the same
            // allowance ProductionSheetService makes for the approval gate: the lot was set under it)
            $rules['clr_status_oracle'] = $request->input('clr_status_oracle') === $lot->clr_status_oracle
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

        $wasColourStatus = $lot->clr_status_oracle;
        $lot->fill($attrs)->save();
        $this->recordColourDecision($lot, $wasColourStatus, $request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);
        Inertia::flash('selectedId', $lot->id);

        return back();
    }

    /**
     * Pin this lot's reporting stage by hand (`production.route.manage`).
     *
     * The permission is checked before anything is validated or read off the request, as `update()` does, so
     * a user who may not do this gets 403 whatever the payload looks like. Only the validated stage id is
     * ever written: `stage_overridden_at` / `_by` are set from the server's clock and the acting user, never
     * from the request.
     *
     * "Active" is checked in PHP rather than inside an `exists` rule, so an inactive stage comes back as a
     * field error on the screen rather than as a database error.
     */
    public function overrideStage(Request $request, Lot $lot): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('production.route.manage'), 403);

        $data = $request->validate([
            'stage_id' => ['required', 'integer', 'exists:stages,id'],
        ]);

        $stage = Stage::query()->whereKey($data['stage_id'])->firstOrFail();
        if ($stage->is_active !== true) {
            throw ValidationException::withMessages(['stage_id' => __('production.lots.stage_inactive')]);
        }

        // An audited save (not a query-builder update), so the audits row records who pinned what, and when.
        $lot->stage_id = (int) $stage->getKey();
        $lot->stage_overridden_at = now();
        $lot->stage_overridden_by = $user->id;
        $lot->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('production.lots.stage_overridden_done')]);
        Inertia::flash('selectedId', $lot->id);

        return back();
    }

    /**
     * Drop the override and re-derive the stage from the lot's current status immediately, so the operator
     * sees the derived value rather than having to wait for the next movement. Its own action, never an
     * empty-value edge case of the override above.
     */
    public function clearStageOverride(Request $request, Lot $lot): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('production.route.manage'), 403);

        $lot->stage_id = $this->stages->stageId($lot->status_oracle);
        $lot->stage_overridden_at = null;
        $lot->stage_overridden_by = null;
        $lot->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('production.lots.stage_override_cleared')]);
        Inertia::flash('selectedId', $lot->id);

        return back();
    }

    /**
     * The colour decision, for the action log and the lot timeline.
     *
     * The `audits` row already records the column change; this is the same fact in the words the colour lab
     * and the floor use, and it is what `ActionKeys::COLOUR_APPROVED` / `COLOUR_REJECTED` were defined for —
     * until now nothing but the demo seeder ever wrote them. Which of the two it is comes from the lookup's
     * own `meta.approved` flag (spec §5.4), the same flag the production sheet's colour gate reads.
     *
     * Clearing the status is not a decision, so it records nothing; neither does a save that left the colour
     * status alone. Logging is best-effort inside ActionLogger and the save has already committed.
     */
    private function recordColourDecision(Lot $lot, ?string $previous, Request $request): void
    {
        $status = $lot->clr_status_oracle;

        if ($status === null || $status === $previous) {
            return;
        }

        $meta = LookupValue::query()->list(ProductionSheetService::COLOUR_STATUS_LIST)
            ->where('code', $status)->first()?->meta;
        $approved = is_array($meta) && (bool) ($meta['approved'] ?? false);

        $this->recordAction(
            $approved ? ActionKeys::COLOUR_APPROVED : ActionKeys::COLOUR_REJECTED,
            $lot,
            ['colour' => (string) $lot->color_oracle_name, 'lot' => $lot->lot_no_oracle],
            $request->user(),
        );
    }

    /**
     * One grid/form record: the Oracle fields the screen shows, the PO header, the state (with the reason and
     * the moment it changed) and label, and the seven derived quantities (spec §5.3 "Derived lot values").
     * `oracle_line_id`, `design_oracle_id` and `color_oracle_id` are deliberately left out: nothing on this screen shows
     * them, and the Lot Inquiry is where the Oracle identifiers belong.
     *
     * @return array<string, mixed>
     */
    private function row(Lot $lot): array
    {
        return [
            'id' => $lot->id, 'lot_no_oracle' => $lot->lot_no_oracle,
            'contract_id' => $lot->contract_id, 'po_no' => $lot->contract->po_no, 'party' => $lot->contract->party->name,
            'ref_no' => $lot->contract->ref_no, 'po_date' => $lot->contract->po_date->format('d-m-Y'),
            'quality' => $lot->quality, 'gsm' => $lot->gsm, 'design' => $lot->design,
            'color_oracle_name' => $lot->color_oracle_name, 'clr_status_oracle' => $lot->clr_status_oracle,
            'gw_oracle' => $lot->gw_oracle === null ? null : (float) $lot->gw_oracle,
            'f_width' => $lot->f_width === null ? null : (float) $lot->f_width,
            'po_type_oracle' => $lot->po_type_oracle, 'state' => $lot->state, 'hold_reason' => $lot->hold_reason, 'cancel_reason' => $lot->cancel_reason,
            'state_changed_at' => $lot->state_changed_at?->format('d-m-Y H:i'),
            'status_oracle' => $lot->status_oracle, 'process_flag' => $this->labeller->processFlag($lot),
            'stage_id' => $lot->stage_id, 'stage' => $lot->stage?->name,
            'stage_overridden' => $lot->isStageOverridden(),
            'stage_overridden_at' => $lot->stage_overridden_at?->format('d-m-Y H:i'),
            'stage_overridden_by' => $lot->stageOverrider?->name,
            'greige_received_oracle' => (float) $lot->greige_received_oracle, 'greige_in_stock_oracle' => (float) $lot->greige_in_stock_oracle, 'qty_issued' => (float) $lot->qty_issued,
            'wip' => (float) $lot->wip, 'finished' => (float) $lot->finished,
            'dispatch' => (float) $lot->dispatch, 'loss_qty' => (float) $lot->loss_qty,
            'last_movement_at' => $lot->last_movement_at?->format('d-m-Y H:i'),
            'oracle_missing_at' => $lot->oracle_missing_at?->format('d-m-Y'), 'remarks' => $lot->remarks,
        ];
    }
}
