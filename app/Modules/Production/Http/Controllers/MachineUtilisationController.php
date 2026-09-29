<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Department;
use App\Modules\Production\Capacity\Utilisation;
use App\Modules\Production\Services\MachineCapacityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Machine and department utilisation over a date range: the capacity screen (`screen()`, Inertia) and the
 * figures it and the dashboard's charts read (`index()`, JSON).
 */
class MachineUtilisationController extends Controller
{
    /** Guardrail on an unbounded range: a year of days is still one indexed sum per machine. */
    public const MAX_DAYS = 366;

    public function __construct(private readonly MachineCapacityService $capacity) {}

    /**
     * The capacity screen. It carries the department list only; the figures come from `index()` below, so one
     * endpoint answers both the screen's own filters and the dashboard's utilisation charts.
     */
    public function screen(): Response
    {
        return Inertia::render('production/Utilisation', [
            'departments' => Department::query()->where('is_active', true)->orderBy('sort')->orderBy('code')->get()
                ->map(fn (Department $d) => [
                    'id' => (int) $d->getKey(),
                    'code' => $d->code,
                    'name' => is_string($d->getAttribute('name')) ? $d->getAttribute('name') : $d->code,
                ])->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $from = CarbonImmutable::parse($filters['from'] ?? CarbonImmutable::today()->startOfMonth())->startOfDay();
        $to = CarbonImmutable::parse($filters['to'] ?? CarbonImmutable::today())->startOfDay();
        // `after_or_equal:from` only fires when `from` is present, so `?to=2020-01-01` alone arrives backwards
        // against the default `from` (the start of this month). Carbon 3's diffInDays is signed, so the cap
        // below would not catch it either and MachineCapacityService::days() would throw an untranslated
        // InvalidArgumentException — a 500 on a screen the user simply mistyped a date on. Same guard as
        // ProductionAnalyticsController.
        if ($to->lessThan($from)) {
            $to = $from;
        }
        if (abs($from->diffInDays($to)) > self::MAX_DAYS) {
            $to = $from->addDays(self::MAX_DAYS);
        }

        $department = isset($filters['department_id'])
            ? Department::query()->whereKey($filters['department_id'])->firstOrFail()
            : null;

        // Computed once and handed to forDepartment(): it would otherwise repeat the machine SELECT and the
        // grouped SUM, on every filter change the dashboard makes.
        $machines = $this->capacity->byMachine($from, $to, $department);

        return response()->json([
            // `max_days` so the screen's "the range was shortened" notice reads the cap from the server
            // instead of hard-coding 366 beside it.
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'max_days' => self::MAX_DAYS],
            'machines' => $machines->map(fn (Utilisation $u) => $u->toArray())->values(),
            'departments' => $this->capacity->byDepartment($from, $to, $department)
                ->map(fn (Utilisation $u) => $u->toArray())->values(),
            'department' => $department === null
                ? null
                : $this->capacity->forDepartment($department, $from, $to, $machines)->toArray(),
        ]);
    }
}
