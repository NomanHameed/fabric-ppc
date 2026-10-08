<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Services\ProductionAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The fabric report's data, as JSON (spec §13, §14) — the stage → stage process grouping PPC's Month Wise
 * Fabric Report reads.
 *
 * The grouping level is **deliberately retained**: every response is stages, each with its processes, each
 * with the report's six quantities and a lot count, plus the `unclassified` row that keeps the rows adding up
 * to the mill. `?shape=flat` adds the lots themselves under each process, in the flat Oracle-shaped payload
 * (`ProductionAnalyticsService::flatLots()`); `?shape=grouped`, the default, returns the totals only, exactly
 * as before.
 *
 * JSON rather than Inertia props, for the same reason `ProductionAnalyticsController` is: the figures are
 * re-read as the operator changes the states and the shape, without a page visit.
 */
class FabricReportController extends Controller
{
    /** The two shapes: totals only, or totals with each process's lots listed flat under it. */
    public const SHAPES = ['grouped', 'flat'];

    /** Flat rows at most across the whole report; the response says how many it actually holds. */
    public const ROW_CAP = 2000;

    /** The lot states the report opens on — the open work, as every other production read does. */
    public const DEFAULT_STATES = ['open', 'hold'];

    public function __construct(private readonly ProductionAnalyticsService $analytics) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'shape' => ['nullable', 'string', 'in:'.implode(',', self::SHAPES)],
            // `states=all` is spelled out rather than being an empty parameter, so "every state" is always a
            // choice someone made and never the result of a dropped query string.
            'states' => ['nullable', 'string'],
        ]);

        $shape = $filters['shape'] ?? 'grouped';
        $states = $this->states($filters['states'] ?? null);

        if ($shape !== 'flat') {
            return response()->json([
                'shape' => $shape,
                'states' => $states,
                'stages' => $this->analytics->byStage($states),
                'rows_total' => null,
                'row_cap' => self::ROW_CAP,
            ]);
        }

        $report = $this->analytics->fabricReport($states, self::ROW_CAP);

        return response()->json([
            'shape' => $shape,
            'states' => $states,
            'stages' => $report['stages'],
            'rows_total' => $report['rows_total'],
            'row_cap' => self::ROW_CAP,
        ]);
    }

    /**
     * The states asked for: a comma-separated list of lot states, `all` for every state, nothing for the
     * open work. An unknown state is dropped rather than queried, and a list that leaves nothing falls back
     * to the default, so the report is never silently empty because of a typo in the query string.
     *
     * @return list<string>|null null means every state (`ProductionAnalyticsService::byStage`'s own spelling)
     */
    private function states(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return self::DEFAULT_STATES;
        }
        if (strtolower(trim($raw)) === 'all') {
            return null;
        }

        $wanted = array_values(array_intersect(
            array_map(fn (string $s): string => strtolower(trim($s)), explode(',', $raw)),
            ['open', 'hold', 'closed', 'cancelled'],
        ));

        return $wanted === [] ? self::DEFAULT_STATES : $wanted;
    }
}
