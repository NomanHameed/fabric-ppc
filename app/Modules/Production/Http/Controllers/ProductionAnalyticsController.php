<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Production\Services\ProductionAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dashboard's data, as JSON.
 *
 * It is a separate endpoint rather than Inertia props on the dashboard page because the page's controller lives
 * in Foundation and the filters — date range, department, process — have to re-run the queries without a full
 * page visit. Everything here is a read; the charts never slice a whole dataset client-side.
 */
class ProductionAnalyticsController extends Controller
{
    /** The same guardrail the utilisation endpoint uses: a year of days is still one grouped scan. */
    public const MAX_DAYS = 366;

    public function __construct(private readonly ProductionAnalyticsService $analytics) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'operation_id' => ['nullable', 'integer', 'exists:operations,id'],
        ]);

        $from = CarbonImmutable::parse($filters['from'] ?? CarbonImmutable::today()->startOfMonth())->startOfDay();
        $to = CarbonImmutable::parse($filters['to'] ?? CarbonImmutable::today())->startOfDay();
        if ($to->lessThan($from)) {
            $to = $from;
        }
        // Absolute: Carbon 3's diffInDays is signed, and the guard above is the only thing keeping it positive.
        if (abs($from->diffInDays($to)) > self::MAX_DAYS) {
            $to = $from->addDays(self::MAX_DAYS);
        }

        $department = isset($filters['department_id'])
            ? Department::query()->whereKey($filters['department_id'])->firstOrFail()
            : null;
        $operation = isset($filters['operation_id'])
            ? Operation::query()->whereKey($filters['operation_id'])->firstOrFail()
            : null;

        // A process implies its department; saying so keeps the chart titles honest when only a process is picked.
        $department ??= $operation?->department;

        // Capacity is a planning figure behind its own permission: the colour lab and dispatch see the
        // production charts but not how committed the machines are.
        $capacity = $request->user()?->can('production.sheet.view') ?? false;

        $payload = $this->analytics->dashboard($from, $to, $department, $operation, withUtilisation: $capacity)->toArray();

        // The cap belongs to this controller, not to the analytics service, so it is merged in here: the
        // dashboard's "the range was shortened" notice reads it instead of hard-coding 366 of its own.
        $range = is_array($payload['range'] ?? null) ? $payload['range'] : [];
        $payload['range'] = $range + ['max_days' => self::MAX_DAYS];

        return response()->json($payload);
    }
}
