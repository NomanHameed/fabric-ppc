<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Shift;
use App\Modules\Foundation\Query\QueryCriteria;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\ProductionLine;
use App\Modules\Production\Models\ProductionSheet;
use App\Modules\Production\Services\BatchSplitter;
use App\Modules\Production\Services\ProductionSheetService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Production sheet writes and the JSON the sheet screen needs (spec §4.4 screen 5, §6 workflow 3). Viewing is
 * `production.sheet.view` (the route group); every write goes through ProductionSheetPolicy, which enforces
 * `production.sheet.edit.all` / `edit.own_department` per department. The service's refusals arrive as
 * ValidationException with `lines.<row>.<field>` keys and reach the form as field errors, never 500s.
 * The page (GET production/sheets) lists the sheets matching the query criteria and, with `?sheet=`, one sheet
 * with its lines and totals; the lookups the form needs travel with it.
 *
 * @phpstan-import-type Header from ProductionSheetService
 * @phpstan-import-type LinePayload from ProductionSheetService
 */
class ProductionSheetController extends Controller
{
    public function __construct(private readonly ProductionSheetService $sheets) {}

    /**
     * The sheet screen: up to 200 sheets matching the query criteria (newest first), the sheet `?sheet=` names with
     * its lines and totals, and the lookups the form needs. `canEditAll` / `canEditOwn` / `ownDepartmentIds` let the
     * screen decide which departments a new sheet may be entered for (and what to tell a user who may enter none);
     * `can_edit` per sheet is the policy's rule (ProductionSheetPolicy::canEditDepartment) computed once from them.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ProductionSheet::class);
        $user = $request->user();
        $criteria = (array) $request->query('q', []);
        $canEditAll = $user->can('production.sheet.edit.all');
        $canEditOwn = $user->can('production.sheet.edit.own_department');
        $ownDepartmentIds = $canEditOwn ? $user->departments()->pluck('departments.id')->map(fn ($id): int => (int) $id)->values()->all() : [];
        $canEdit = fn (int $departmentId): bool => $canEditAll || in_array($departmentId, $ownDepartmentIds, true);
        // select() before withCount/withSum: a later select() would drop their sub-selects
        $query = ProductionSheet::query()->with(['shift', 'department', 'machine', 'enteredBy'])
            ->join('shifts', 'shifts.id', '=', 'production_sheets.shift_id')
            ->join('departments', 'departments.id', '=', 'production_sheets.department_id')
            ->join('machines', 'machines.id', '=', 'production_sheets.machine_id')
            ->select('production_sheets.*')->withCount('lines')->withSum('lines', 'qty_out');
        QueryCriteria::apply($query, $criteria, [
            'sheet_date' => ['column' => 'production_sheets.sheet_date', 'type' => 'date'],
            'shift' => ['column' => 'shifts.code', 'type' => 'text'],
            'department' => ['column' => 'departments.code', 'type' => 'text'],
            'machine' => ['column' => 'machines.code', 'type' => 'text'],
        ]);
        $selected = $request->filled('sheet')
            ? ProductionSheet::query()->with(['lines.lot.contract', 'lines.operation', 'lines.colourOverride'])->find($request->integer('sheet'))
            : null;

        return Inertia::render('production/ProductionSheet', [
            'sheets' => $query->orderByDesc('production_sheets.sheet_date')->orderByDesc('production_sheets.id')->limit(200)->get()
                ->map(fn (ProductionSheet $s): array => [
                    'id' => $s->id, 'sheet_date' => $s->sheet_date->format('d-m-Y'), 'shift' => $s->shift->code,
                    'department' => $s->department->getAttribute('name'), 'department_id' => $s->department_id, 'machine' => $s->machine->name,
                    'lines_count' => (int) $s->lines_count, 'qty_out' => (float) ($s->lines_sum_qty_out ?? 0),
                    'entered_by' => $s->enteredBy?->name, 'can_edit' => $canEdit($s->department_id),
                ])->values(),
            'selected' => $selected === null ? null : [
                'id' => $selected->id, 'sheet_date' => $selected->sheet_date->format('d-m-Y'), 'shift_id' => $selected->shift_id,
                'department_id' => $selected->department_id, 'machine_id' => $selected->machine_id, 'downtime_minutes' => $selected->downtime_minutes,
                'remarks' => $selected->remarks, 'can_edit' => $canEdit($selected->department_id),
                'lines' => $selected->lines->map(fn (ProductionLine $l): array => [
                    'id' => $l->id, 'batch_no' => $l->batch_no, 'lot_id' => $l->lot_id,
                    'lot' => "{$l->lot->lot_no} · {$l->lot->contract->po_no} · {$l->lot->design} · {$l->lot->colour}",
                    'lot_route_step_id' => $l->lot_route_step_id, 'operation_id' => $l->operation_id, 'operation' => $l->operation->name,
                    'is_stretch' => (bool) $l->operation->is_stretch, 'is_reprocess' => $l->is_reprocess, 'reprocess_reason' => $l->reprocess_reason,
                    'qty_in' => (float) $l->qty_in, 'qty_out' => (float) $l->qty_out, 'output_type' => $l->output_type,
                    'g_width' => $l->g_width === null ? null : (float) $l->g_width, 'f_width' => $l->f_width === null ? null : (float) $l->f_width,
                    'remarks' => $l->remarks, 'colour_override' => $l->colourOverride?->reason,
                ])->values(),
                'totals' => $this->sheets->totals($selected),
            ],
            'shifts' => Shift::query()->orderBy('code')->get()
                ->map(fn (Shift $s): array => ['value' => $s->id, 'label' => "{$s->code} — {$s->name}", 'code' => $s->code])->values(),
            'departments' => Department::query()->where('is_active', true)->orderBy('sort')->get()
                ->map(fn (Department $d): array => ['value' => $d->id, 'label' => $d->getAttribute('name'), 'code' => $d->code])->values(),
            'machines' => Machine::query()->with('departments')->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Machine $m): array => [
                    'value' => $m->id, 'label' => $m->name, 'code' => $m->code, 'department_ids' => $m->departments->pluck('id')->values()->all(),
                ])->values(),
            // every active operation: the grid resolves an operation's stretch flag and default output type from it
            'operations' => Operation::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Operation $o): array => [
                    'value' => $o->id, 'label' => $o->name, 'department_id' => $o->department_id, 'kind' => $o->kind,
                    'is_stretch' => (bool) $o->is_stretch, 'finish_type' => $o->finish_type,
                ])->values(),
            'reprocessReasons' => LookupValue::query()->list(ProductionSheetService::REPROCESS_REASON_LIST)->where('is_active', true)->get()
                ->map(fn (LookupValue $v): array => ['value' => $v->code, 'label' => $v->getAttribute('label')])->values(),
            'canEditAll' => $canEditAll,
            'canEditOwn' => $canEditOwn,
            'ownDepartmentIds' => $ownDepartmentIds,
            'criteria' => (object) $criteria,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // rights first (403 before 422): a user who may not enter this department gets no validation feedback
        $departmentId = filter_var($request->input('department_id'), FILTER_VALIDATE_INT);
        if ($departmentId !== false) {
            Gate::authorize('create', [ProductionSheet::class, $departmentId]);
        }
        [$header, $lines] = $this->validated($request, null);
        Gate::authorize('create', [ProductionSheet::class, $header['department_id']]); // the validated id, whatever arrived
        $sheet = $this->sheets->save(null, $header, $lines, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);
        Inertia::flash('selectedId', $sheet->id);

        return back();
    }

    public function update(Request $request, ProductionSheet $sheet): RedirectResponse
    {
        Gate::authorize('update', $sheet);
        [$header, $lines] = $this->validated($request, $sheet);
        Gate::authorize('create', [ProductionSheet::class, (int) $header['department_id']]); // moving a sheet to another department needs rights there too
        $this->sheets->save($sheet, $header, $lines, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }

    public function destroy(Request $request, ProductionSheet $sheet): RedirectResponse
    {
        Gate::authorize('delete', $sheet);
        $this->sheets->delete($sheet, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.deleted')]);

        return back();
    }

    /** JSON: the sheet's footer totals (fresh / reprocess / stretching / total, per output type). */
    public function totals(ProductionSheet $sheet): JsonResponse
    {
        Gate::authorize('view', $sheet);

        return response()->json(['totals' => $this->sheets->totals($sheet)]);
    }

    /** JSON: "split batch total" — a proportional split of a batch total over the given lines' available metres. */
    public function splitBatch(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ProductionSheet::class);
        $data = $request->validate([
            'total' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'available' => ['required', 'array', 'min:1', 'max:200'],
            'available.*' => ['required', 'numeric', 'min:0'],
        ]);

        return response()->json(['allocations' => BatchSplitter::split((float) $data['total'], array_map('floatval', $data['available']))]);
    }

    /** JSON: identity, balances, default route step, gate flag and steps for a lot picked on the sheet. */
    public function lotContext(Request $request, Lot $lot): JsonResponse
    {
        Gate::authorize('viewAny', ProductionSheet::class);
        $data = $request->validate(['department_id' => ['required', 'integer', 'exists:departments,id']]);

        return response()->json($this->sheets->lotContext($lot, (int) $data['department_id']));
    }

    /**
     * The validated header and lines, typed the way the service takes them. Quantities are `decimal:0,2` and
     * `min:0.01` for G/Mtrs in (F/Mtrs out may be 0: a total loss at the operation), like Issue to Floor.
     *
     * @return array{0: Header, 1: list<LinePayload>}
     */
    private function validated(Request $request, ?ProductionSheet $sheet): array
    {
        $data = $request->validate([
            'sheet_date' => ['required', 'date_format:d-m-Y', 'before_or_equal:'.now()->format('d-m-Y')],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'machine_id' => ['required', 'integer', Rule::exists('machines', 'id')->where('is_active', true),
                Rule::unique('production_sheets', 'machine_id')
                    ->where('sheet_date', $this->date($request->input('sheet_date')))
                    ->where('shift_id', $request->input('shift_id'))
                    ->ignore($sheet?->id)],
            'downtime_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'lines' => ['present', 'array', 'max:500'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:30'],
            'lines.*.lot_id' => ['required', 'integer', 'exists:lots,id'],
            'lines.*.lot_route_step_id' => ['nullable', 'integer'],
            'lines.*.operation_id' => ['required', 'integer', 'exists:operations,id'],
            'lines.*.is_reprocess' => ['nullable', 'boolean'],
            'lines.*.reprocess_reason' => ['nullable', 'string', 'max:40'],
            'lines.*.qty_in' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'lines.*.qty_out' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'lines.*.output_type' => ['nullable', Rule::in(ProductionLine::OUTPUT_TYPES)],
            'lines.*.g_width' => ['nullable', 'numeric', 'between:0,9999.99'],
            'lines.*.f_width' => ['nullable', 'numeric', 'between:0,9999.99'],
            'lines.*.remarks' => ['nullable', 'string', 'max:200'],
            'lines.*.override_reason' => ['nullable', 'string', 'max:200'],
            'lines.*.colour_override_reason' => ['nullable', 'string', 'max:200'],
        ], [], $this->attributes());

        $header = [
            'sheet_date' => (string) $this->date($data['sheet_date']),
            'shift_id' => (int) $data['shift_id'],
            'department_id' => (int) $data['department_id'],
            'machine_id' => (int) $data['machine_id'],
            'downtime_minutes' => (int) ($data['downtime_minutes'] ?? 0),
            'remarks' => $data['remarks'] ?? null,
        ];

        return [$header, array_values(array_map($this->line(...), $data['lines']))];
    }

    /**
     * One validated line row as the service's LinePayload (ids and quantities cast, optional text trimmed to null).
     *
     * @param  array<string, mixed>  $l
     * @return LinePayload
     */
    private function line(array $l): array
    {
        $int = fn (mixed $v): ?int => $v === null || $v === '' ? null : (int) $v;
        $float = fn (mixed $v): ?float => $v === null || $v === '' ? null : (float) $v;
        $text = fn (mixed $v): ?string => $v === null || trim((string) $v) === '' ? null : trim((string) $v);

        return [
            'id' => $int($l['id'] ?? null),
            'batch_no' => $text($l['batch_no'] ?? null),
            'lot_id' => (int) $l['lot_id'],
            'lot_route_step_id' => $int($l['lot_route_step_id'] ?? null),
            'operation_id' => (int) $l['operation_id'],
            'is_reprocess' => (bool) ($l['is_reprocess'] ?? false),
            'reprocess_reason' => $text($l['reprocess_reason'] ?? null),
            'qty_in' => (float) $l['qty_in'],
            'qty_out' => (float) $l['qty_out'],
            'output_type' => $text($l['output_type'] ?? null),
            'g_width' => $float($l['g_width'] ?? null),
            'f_width' => $float($l['f_width'] ?? null),
            'remarks' => $text($l['remarks'] ?? null),
            'override_reason' => $text($l['override_reason'] ?? null),
            'colour_override_reason' => $text($l['colour_override_reason'] ?? null),
        ];
    }

    /**
     * Field names for the validator's messages (`lines.*.qty_in` reads as "G/Mtrs in", not "lines.0.qty_in").
     *
     * @return array<string, string>
     */
    private function attributes(): array
    {
        $names = [
            'sheet_date' => 'sheet_date', 'shift_id' => 'shift', 'department_id' => 'department', 'machine_id' => 'machine',
            'downtime_minutes' => 'downtime', 'remarks' => 'remarks', 'lines.*.batch_no' => 'batch_no', 'lines.*.lot_id' => 'lot_no',
            'lines.*.lot_route_step_id' => 'step', 'lines.*.operation_id' => 'operation', 'lines.*.is_reprocess' => 'reprocess',
            'lines.*.reprocess_reason' => 'reprocess_reason', 'lines.*.qty_in' => 'qty_in', 'lines.*.qty_out' => 'qty_out',
            'lines.*.output_type' => 'output_type', 'lines.*.g_width' => 'g_width', 'lines.*.f_width' => 'f_width', 'lines.*.remarks' => 'remarks',
            'lines.*.override_reason' => 'override_reason', 'lines.*.colour_override_reason' => 'colour_override_reason',
        ];

        return array_map(fn (string $key): string => __('production.fields.'.$key), $names);
    }

    private function date(mixed $value): ?string
    {
        try {
            return CarbonImmutable::createFromFormat('!d-m-Y', (string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
