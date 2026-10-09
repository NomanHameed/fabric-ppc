<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Setting;
use App\Modules\Production\Services\ProductionAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * R1 — the Month Wise Fabric Report (spec §7.1, §4.3): the screen that draws it (`screen()`, Inertia) and
 * the same figures as JSON (`index()`), which the dashboard and anything else reading the grouping use.
 *
 * The grouping level is **deliberately retained**: every response is stages, each with its processes, each
 * with the report's six quantities and a lot count, plus the `unclassified` row that keeps the rows adding up
 * to the mill. `?shape=flat` adds the lots themselves under each process, in the flat Oracle-shaped payload
 * (`ProductionAnalyticsService::flatLots()`); `?shape=grouped`, the default, returns the totals only, exactly
 * as before.
 *
 * **Parameters** (spec §7.1): Month (All, or a month of the PO date), Process (Pending / Complete / All,
 * default Pending), Party, PO Type — plus this branch's Stage / Stage Process, and the Oracle design code.
 * Month becomes a `po_date` range and every other parameter is a criterion over
 * `ProductionAnalyticsService::LOT_CRITERIA`, so the report's query mode is the same `QueryCriteria` plumbing
 * every other Forms screen uses (`%` wildcards, `>`/`<`, `a..b`, `#NULL`) rather than a parallel filter set.
 *
 * **States are not a parameter here.** Process is: `pending` already excludes a closed or cancelled lot and
 * `complete` includes it, so the report reads every state and lets Process do that work — otherwise
 * "Complete" would be filtered down to the open work and print nothing.
 *
 * **Totals and rows come from different queries, on purpose.** The three totals levels are summed over every
 * match in the database; the rows are capped at `ROW_CAP` across the whole report. When more lots match than
 * the cap allows, a stage can print a total with fewer rows beneath it, so `rows_total` (every match, from
 * `flatLotsCount()`) travels beside `rows_shown` and the screen says the report was truncated.
 */
class FabricReportController extends Controller
{
    /** The two shapes: totals only, or totals with each process's lots listed flat under it. */
    public const SHAPES = ['grouped', 'flat'];

    /** Flat rows at most across the whole report; the response says how many it actually holds. */
    public const ROW_CAP = 2000;

    /** The lot states the JSON endpoint opens on — the open work, as every other production read does. */
    public const DEFAULT_STATES = ['open', 'hold'];

    /** R1's Process parameter, and the one it opens on (spec §7.1). */
    public const DEFAULT_PROCESS = 'pending';

    public function __construct(private readonly ProductionAnalyticsService $analytics) {}

    /**
     * The report screen. Its props are the finished report — grouping, rows and all three totals levels —
     * because a report is read, not edited: there is nothing on this screen to keep in sync with a second
     * fetch, and `Inertia::render` already gives the partial reload F8 needs.
     */
    public function screen(Request $request): Response
    {
        $filters = $this->filters($request);
        $criteria = $this->criteria($request, $filters['month']);
        $process = $filters['process'] ?? self::DEFAULT_PROCESS;

        // Every state: Process decides what counts as finished work, so narrowing by state as well would
        // hide exactly the lots "Complete" is asking for.
        $report = $this->analytics->fabricReport(null, self::ROW_CAP, $criteria, $process);
        $user = $request->user();

        return Inertia::render('production/FabricReport', [
            'stages' => $report['stages'],
            'totals' => $report['totals'],
            'rowsShown' => $report['rows_total'],
            // The true number of matches, which the capped row list cannot tell us.
            'rowsTotal' => $this->analytics->flatLotsCount($criteria, null, $process),
            'rowCap' => self::ROW_CAP,
            'months' => $this->analytics->reportMonths(),
            'params' => ['month' => $filters['month'], 'process' => $process],
            // What the server actually ran, as the Forms screens send it back: the boxes in query mode are
            // the page's own state.
            'criteria' => (object) $this->rawCriteria($request),
            // The Oracle Reports header block (spec §4.3). The title and the parameter list are built on the
            // page, from `t()`, so they follow the viewer's language; these three cannot be.
            'header' => [
                'company' => $this->company(),
                'run_at' => CarbonImmutable::now()->format('d-m-Y H:i'),
                'user' => $user === null ? '' : (string) $user->name,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $criteria = $this->criteria($request, $filters['month']);
        $process = $filters['process'];

        $shape = $filters['shape'] ?? 'grouped';
        $states = $this->states($filters['states'] ?? null);

        if ($shape !== 'flat') {
            return response()->json([
                'shape' => $shape,
                'states' => $states,
                'process' => $process,
                'stages' => $this->analytics->byStage($states, $criteria, $process),
                'rows_total' => null,
                'row_cap' => self::ROW_CAP,
            ]);
        }

        $report = $this->analytics->fabricReport($states, self::ROW_CAP, $criteria, $process);

        return response()->json([
            'shape' => $shape,
            'states' => $states,
            'process' => $process,
            'stages' => $report['stages'],
            'totals' => $report['totals'],
            'rows_total' => $report['rows_total'],
            'rows_matched' => $this->analytics->flatLotsCount($criteria, $states, $process),
            'row_cap' => self::ROW_CAP,
        ]);
    }

    /**
     * The parameters both entry points accept. An unknown `shape` or `process` is a 422, never a silent
     * fallback: a report that quietly answered a different question than the one in the URL would be
     * believed.
     *
     * @return array{shape?: string|null, states?: string|null, month: string|null, process: string|null}
     */
    private function filters(Request $request): array
    {
        $filters = $request->validate([
            'shape' => ['nullable', 'string', 'in:'.implode(',', self::SHAPES)],
            // `states=all` is spelled out rather than being an empty parameter, so "every state" is always a
            // choice someone made and never the result of a dropped query string.
            'states' => ['nullable', 'string'],
            // A month of the PO date, `YYYY-MM`; absent means every month ("All").
            'month' => ['nullable', 'string', 'date_format:Y-m'],
            'process' => ['nullable', 'string', 'in:'.implode(',', ProductionAnalyticsService::PROCESSES)],
            // The criteria themselves are parsed by QueryCriteria over LOT_CRITERIA, which rejects an array
            // value and a malformed number or date with a field error of its own.
            'q' => ['nullable', 'array'],
        ]);

        /** @var array{shape?: string|null, states?: string|null, month: string|null, process: string|null} $out */
        $out = $filters + ['month' => null, 'process' => null];

        return $out;
    }

    /**
     * The criteria as the operator typed them, untouched: what the screen's query-mode boxes hold.
     *
     * @return array<string, mixed>
     */
    private function rawCriteria(Request $request): array
    {
        $q = $request->query('q', []);

        return is_array($q) ? $q : [];
    }

    /**
     * The criteria to run: the operator's own, plus Month as a `po_date` range.
     *
     * Month wins over a hand-typed `q[po_date]` — it is the report's own dedicated parameter, and two
     * conflicting date filters would otherwise silently intersect to nothing.
     *
     * @return array<string, mixed>
     */
    private function criteria(Request $request, ?string $month): array
    {
        $criteria = $this->rawCriteria($request);

        if ($month !== null) {
            $from = CarbonImmutable::createFromFormat('!Y-m', $month);
            $criteria['po_date'] = $from->format('d-m-Y').'..'.$from->endOfMonth()->format('d-m-Y');
        }

        return $criteria;
    }

    /** The company name the report header prints, as the Settings screen stores it. */
    private function company(): string
    {
        $name = Setting::get('company_name', config('app.name'));

        return is_string($name) && trim($name) !== '' ? $name : 'Fabric PPC';
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
