<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
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

/**
 * Production sheet writes and the JSON the sheet screen needs (spec §4.4 screen 5, §6 workflow 3). Viewing is
 * `production.sheet.view` (the route group); every write goes through ProductionSheetPolicy, which enforces
 * `production.sheet.edit.all` / `edit.own_department` per department. The service's refusals arrive as
 * ValidationException with `lines.<row>.<field>` keys and reach the form as field errors, never 500s.
 * The page itself (GET production/sheets) is Task 10.
 */
class ProductionSheetController extends Controller
{
    public function __construct(private readonly ProductionSheetService $sheets) {}

    public function store(Request $request): RedirectResponse
    {
        [$header, $lines] = $this->validated($request, null);
        Gate::authorize('create', [ProductionSheet::class, (int) $header['department_id']]);
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
     * @return array{0: array{sheet_date: string, shift_id: int, department_id: int, machine_id: int, downtime_minutes: int, remarks: string|null}, 1: list<array<string, mixed>>}
     */
    private function validated(Request $request, ?ProductionSheet $sheet): array
    {
        $data = $request->validate([
            'sheet_date' => ['required', 'date_format:d-m-Y'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'machine_id' => ['required', 'integer', 'exists:machines,id',
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
            'lines.*.qty_in' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'lines.*.qty_out' => ['required', 'numeric', 'decimal:0,2', 'gte:0', 'max:9999999999.99'],
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

        return [$header, array_values($data['lines'])];
    }

    /** Field names for the validator's messages (`lines.*.qty_in` reads as "G/Mtrs in", not "lines.0.qty_in"). */
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
