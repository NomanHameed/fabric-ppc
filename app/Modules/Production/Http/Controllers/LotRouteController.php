<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Masters\MasterController;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotRouteStep;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * Lot Route editor (`production.route.manage`, spec §4.4 screen 3, §6.2): a lot's planned steps with the metres
 * waiting after each one, saved as a whole through LotRouteService::replace(); skip/unskip; and a template applied
 * to one lot or to every open lot of a PO. The service's refusals (DomainException) become errors on `steps`
 * (the grid), `steps.<row>.skipped` (one row) or `route_template_id`, never 500s.
 */
class LotRouteController extends Controller
{
    public function __construct(private readonly LotRouteService $routes, private readonly LedgerService $ledger) {}

    public function index(Request $request): Response
    {
        $lot = $request->filled('lot')
            ? Lot::query()->with('contract.party')->findOrFail($request->integer('lot'))
            : null;
        $balances = $lot === null ? [] : $this->ledger->balances($lot);
        $locked = $lot === null ? [] : $this->routes->lockedStepIds($lot);

        return Inertia::render('production/LotRoute', [
            'lot' => $lot === null ? null : [
                'id' => $lot->id, 'lot_no_oracle' => $lot->lot_no_oracle, 'po_no' => $lot->contract->po_no, 'party' => $lot->contract->party->name,
                'contract_id' => $lot->contract_id, 'quality' => $lot->quality, 'design' => $lot->design, 'color_oracle_name' => $lot->color_oracle_name,
                'po_type_oracle' => $lot->po_type_oracle, 'state' => $lot->state, 'status_oracle' => $lot->status_oracle,
                'greige_in_stock_oracle' => (float) $lot->greige_in_stock_oracle, 'issued' => (float) ($balances[Position::ISSUED] ?? 0),
                'wip' => (float) $lot->wip, 'finished' => (float) $lot->finished,
            ],
            'steps' => $lot === null ? [] : $lot->routeSteps()->with('operation.department')->get()
                ->map(fn (LotRouteStep $s): array => $this->stepRow($s, $balances, $locked))->values(),
        ]);
    }

    /** Save the whole route: order, operations, added and removed steps, then the skip flags row by row. */
    public function update(Request $request, Lot $lot): RedirectResponse
    {
        $data = $request->validate([
            'steps' => ['present', 'array', 'max:100'],
            'steps.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('lot_route_steps', 'id')->where('lot_id', $lot->id)],
            'steps.*.operation_id' => ['required', 'integer', Rule::exists('operations', 'id')->where('is_active', true)],
            'steps.*.skipped' => ['sometimes', 'boolean'],
        ]);
        /** @var list<array{id: int|null, operation_id: int, skipped: bool}> $steps */
        $steps = array_values(array_map(fn (array $s): array => [
            'id' => isset($s['id']) ? (int) $s['id'] : null,
            'operation_id' => (int) $s['operation_id'],
            'skipped' => (bool) ($s['skipped'] ?? false),
        ], $data['steps']));

        try {
            // One transaction around the service calls: a refused skip rolls the replace back too.
            DB::transaction(function () use ($lot, $steps): void {
                $this->routes->replace($lot, array_map(fn (array $s) => ['id' => $s['id'], 'operation_id' => $s['operation_id']], $steps));
                // replace() writes the rows in payload order (seq = row * 10), so row i of the payload is saved step i:
                // that is how a step added and skipped in the same save (id null) finds its row.
                $saved = $lot->routeSteps()->get()->values();
                if ($saved->count() !== count($steps)) {
                    throw new LogicException("LotRouteService::replace() left {$saved->count()} steps on lot {$lot->id} for a payload of ".count($steps).'.');
                }
                foreach ($steps as $i => $s) {
                    $step = $saved->get($i);
                    if ($step !== null && $s['skipped'] !== ($step->status === 'skipped')) {
                        $this->skipRow($step, $s['skipped'], "steps.{$i}.skipped");
                    }
                }
            });
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['steps' => $e->getMessage()]);
        } catch (QueryException $e) {
            if (! MasterController::isForeignKeyViolation($e)) {
                throw $e;
            }
            throw ValidationException::withMessages(['steps' => __('production.errors.route_step_in_use')]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }

    /** Skip or un-skip one step of the lot's route without touching the rest. */
    public function skip(Request $request, Lot $lot, LotRouteStep $step): RedirectResponse
    {
        abort_unless($step->lot_id === $lot->id, 404);
        $data = $request->validate(['skipped' => ['required', 'boolean']]);

        $this->skipRow($step, (bool) $data['skipped'], 'skipped');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }

    /** Replace the lot's route with a template's steps (refused once the lot has production on its route). */
    public function applyToLot(Request $request, Lot $lot): RedirectResponse
    {
        $template = $this->template($request);
        try {
            $n = $this->routes->assign($lot, $template, replace: true);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['route_template_id' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('production.route.applied_lot', ['n' => $n, 'lot' => $lot->lot_no_oracle])]);

        return back();
    }

    /** Apply a template to every open lot of the PO that has no production yet; the toast says how many took it. */
    public function applyToContract(Request $request, Contract $contract): RedirectResponse
    {
        $template = $this->template($request);
        try {
            $n = $this->routes->applyTemplateToContract($contract, $template);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['route_template_id' => $e->getMessage()]);
        }
        Inertia::flash('toast', [
            'type' => $n > 0 ? 'success' : 'info',
            'message' => __($n > 0 ? 'production.route.applied' : 'production.route.applied_none', ['n' => $n, 'po' => $contract->po_no]),
        ]);

        return back();
    }

    private function template(Request $request): RouteTemplate
    {
        $data = $request->validate(['route_template_id' => ['required', 'integer', 'exists:route_templates,id']]);

        return RouteTemplate::query()->findOrFail((int) $data['route_template_id']);
    }

    private function skipRow(LotRouteStep $step, bool $skipped, string $errorKey): void
    {
        try {
            $this->routes->setSkipped($step, $skipped);
        } catch (DomainException $e) {
            throw ValidationException::withMessages([$errorKey => $e->getMessage()]);
        }
    }

    /**
     * A step is locked (read-only on the screen) when LotRouteService::lockedStepIds() says so — the same facts
     * it refuses removing, moving, skipping or re-operating the step on, including metres processed through it
     * before its status caught up.
     *
     * @param  array<string, float>  $balances
     * @param  list<int>  $locked
     * @return array<string, mixed>
     */
    private function stepRow(LotRouteStep $s, array $balances, array $locked): array
    {
        $department = $s->operation->department; // Department::name is a locale-aware accessor, so it is read as an attribute

        return [
            'id' => $s->id, 'seq' => $s->seq, 'operation_id' => $s->operation_id, 'operation' => $s->operation->name,
            'department' => $department instanceof Department ? $department->getAttribute('name') : null, 'status' => $s->status, 'is_added' => $s->is_added,
            'at_position' => (float) ($balances[Position::step($s->id)->value()] ?? 0),
            'locked' => in_array($s->id, $locked, true),
        ];
    }
}
