<?php

namespace App\Modules\Production\Services;

use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Production\Analytics\DashboardData;
use App\Modules\Production\Capacity\Utilisation;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\LotMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's numbers, read out of the ledger. Nothing here writes: `lot_movements` and `lot_balances`
 * stay LedgerService's, and this is a query over them.
 *
 * Two rules the charts depend on:
 *
 *  - **What counts as production.** A planned production line moves metres from the previous position to the
 *    step's own position (`STEP:<id>`), so metres *arriving* at a step are metres that step has finished.
 *    Production is therefore credited to the department owning that step's operation, on the day the movement
 *    is dated — not the day it was keyed.
 *  - **Reversals subtract.** LedgerService reverses by posting the same `kind` with from/to swapped, so a
 *    reversal is counted as a negative at the position the original delivered to. Without that, a corrected
 *    mistake would show as two days of production.
 *
 * Cost, stated plainly: the step → department mapping cannot be done in SQL without joining on a substring of
 * `to_position`, which no index would serve and whose CAST spelling differs between MySQL and SQLite. The
 * grouped rows (one per step per day that step moved metres) are mapped in PHP instead. The range is capped
 * at a year by the controller, and a month — the default — is a few thousand rows at mill volumes.
 */
final class ProductionAnalyticsService
{
    /** Ledger kinds that represent work done on the floor. */
    private const PRODUCTION_KINDS = ['process', 'reprocess'];

    /**
     * Signed metres for one movement: forward rows deliver `qty_into_to`, reversals take back what the row
     * they reverse delivered (`qty_out_of_from`, which `reverse()` copied from the original's into-quantity).
     */
    private const SIGNED_QTY = 'sum(case when reversal_of_id is null then qty_into_to else -qty_out_of_from end)';

    /** The position the metres landed at — the reversal's origin is the position it is taking them back from. */
    private const EFFECTIVE_POSITION = 'case when reversal_of_id is null then to_position else from_position end';

    public function __construct(private readonly MachineCapacityService $capacity) {}

    public function dashboard(
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Department $department = null,
        ?Operation $operation = null,
        bool $withUtilisation = true,
    ): DashboardData {
        $dates = $this->dates($from, $to);
        $index = array_flip($dates);
        $steps = $this->stepOwners();

        $byDepartment = $this->productionByDepartment($from, $to, $index, $steps, $department, $operation);
        $flow = $this->flow($from, $to, $index, $byDepartment, $department !== null || $operation !== null);
        $stages = $this->wipByStage($steps, $department);

        return new DashboardData(
            from: $from->toDateString(),
            to: $to->toDateString(),
            days: count($dates),
            dates: $dates,
            byDepartment: $byDepartment,
            flow: $flow,
            wipByStage: $stages,
            kpi: [
                'produced' => $this->round(array_sum(array_column($byDepartment, 'total'))),
                'issued' => $this->totalOf($flow, 'issued'),
                'dispatched' => $this->totalOf($flow, 'dispatched'),
                'loss' => $this->totalOf($flow, 'loss'),
                'wip' => $this->round(array_sum(array_map(
                    fn (array $s): float => str_starts_with($s['key'], 'department:') || $s['key'] === 'issued' ? $s['qty'] : 0.0,
                    $stages,
                ))),
            ],
            utilisation: $withUtilisation ? [
                'machines' => array_values($this->capacity->byMachine($from, $to, $department)
                    ->map(fn (Utilisation $u) => $u->toArray())->all()),
                'departments' => array_values($this->capacity->byDepartment($from, $to, $department)
                    ->map(fn (Utilisation $u) => $u->toArray())->all()),
            ] : null,
            filters: [
                'departments' => $this->departmentOptions(),
                'operations' => $this->operationOptions(),
                'applied' => [
                    'department_id' => $department?->getKey() === null ? null : (int) $department->getKey(),
                    'operation_id' => $operation?->getKey() === null ? null : (int) $operation->getKey(),
                ],
            ],
        );
    }

    // ---- series ----------------------------------------------------------------------------------------

    /**
     * Metres finishing an operation, per department per day. Filters narrow which steps are counted, never
     * which days are shown: an empty department still gets its zero-line so the stack stays comparable.
     *
     * @param  array<string, int>  $index  date => offset on the x axis
     * @param  array<int, array{operation_id: int, department_id: int}>  $steps
     * @return list<array{id: int|null, key: string, name: string, total: float, data: list<float>}>
     */
    private function productionByDepartment(
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $index,
        array $steps,
        ?Department $department,
        ?Operation $operation,
    ): array {
        $rows = LotMovement::query()
            ->whereIn('kind', self::PRODUCTION_KINDS)
            ->whereBetween('moved_at', [$from->startOfDay(), $to->endOfDay()])
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('reversal_of_id')->where('to_position', 'like', 'STEP:%'))
                ->orWhere(fn ($q) => $q->whereNotNull('reversal_of_id')->where('from_position', 'like', 'STEP:%')))
            ->selectRaw('date(moved_at) as d, '.self::EFFECTIVE_POSITION.' as p, '.self::SIGNED_QTY.' as q')
            ->groupByRaw('date(moved_at), '.self::EFFECTIVE_POSITION)
            ->get();

        /** @var array<int, list<float>> $totals department id => per-day metres */
        $totals = [];
        foreach ($rows as $row) {
            $stepId = Position::from((string) $row->getAttribute('p'))->stepId();
            $owner = $stepId === null ? null : ($steps[$stepId] ?? null);
            if ($owner === null) {
                continue; // a step deleted since the movement: its department is no longer knowable
            }
            if ($department !== null && $owner['department_id'] !== (int) $department->getKey()) {
                continue;
            }
            if ($operation !== null && $owner['operation_id'] !== (int) $operation->getKey()) {
                continue;
            }
            $day = $index[$this->dayOf((string) $row->getAttribute('d'))] ?? null;
            if ($day === null) {
                continue;
            }
            $totals[$owner['department_id']] ??= array_fill(0, count($index), 0.0);
            $totals[$owner['department_id']][$day] += (float) $row->getAttribute('q');
        }

        $series = [];
        foreach ($this->departments($department) as $d) {
            $data = $totals[(int) $d->getKey()] ?? array_fill(0, count($index), 0.0);
            $series[] = [
                'id' => (int) $d->getKey(),
                'key' => 'department:'.$d->getKey(),
                'name' => $this->departmentName($d),
                'total' => $this->round(array_sum($data)),
                'data' => array_map(fn (float $v): float => $this->round($v), $data),
            ];
        }

        return $series;
    }

    /**
     * The mill's daily flow: metres produced, issued to the floor, dispatched and lost.
     *
     * Produced is the department series summed, so it obeys the filters. Issue, dispatch and loss are lot-level
     * ledger kinds with no department of their own; when a filter is on they are dropped rather than shown
     * unfiltered next to a filtered line, which would be a lie by juxtaposition.
     *
     * @param  array<string, int>  $index
     * @param  list<array{id: int|null, key: string, name: string, total: float, data: list<float>}>  $byDepartment
     * @return list<array{id: int|null, key: string, name: string, total: float, data: list<float>}>
     */
    private function flow(CarbonImmutable $from, CarbonImmutable $to, array $index, array $byDepartment, bool $filtered): array
    {
        $produced = array_fill(0, count($index), 0.0);
        foreach ($byDepartment as $series) {
            foreach ($series['data'] as $i => $qty) {
                $produced[$i] += $qty;
            }
        }

        $series = [$this->flowSeries('produced', array_values($produced))];
        if ($filtered) {
            return $series;
        }

        foreach (['issued' => ['issue'], 'dispatched' => ['dispatch'], 'loss' => ['loss']] as $key => $kinds) {
            $series[] = $this->flowSeries($key, $this->byDay($from, $to, $kinds, $index));
        }

        return $series;
    }

    /**
     * @param  list<float>  $data
     * @return array{id: null, key: string, name: string, total: float, data: list<float>}
     */
    private function flowSeries(string $key, array $data): array
    {
        return [
            'id' => null,
            'key' => $key,
            'name' => $this->line('dashboard.flow.'.$key),
            'total' => $this->round(array_sum($data)),
            'data' => array_map(fn (float $v): float => $this->round($v), $data),
        ];
    }

    /**
     * @param  list<string>  $kinds
     * @param  array<string, int>  $index
     * @return list<float>
     */
    private function byDay(CarbonImmutable $from, CarbonImmutable $to, array $kinds, array $index): array
    {
        $data = array_fill(0, count($index), 0.0);

        $rows = LotMovement::query()
            ->whereIn('kind', $kinds)
            ->whereBetween('moved_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('date(moved_at) as d, '.self::SIGNED_QTY.' as q')
            ->groupByRaw('date(moved_at)')
            ->get();

        foreach ($rows as $row) {
            $day = $index[$this->dayOf((string) $row->getAttribute('d'))] ?? null;
            if ($day !== null) {
                $data[$day] += (float) $row->getAttribute('q');
            }
        }

        return array_values($data);
    }

    /**
     * Where the mill's metres sit *now* — a snapshot from `lot_balances`, not a range. Stock and issued-but-
     * not-started are their own stages; everything at a route step is grouped under the department that owns
     * it; finished metres are the last stage. Dispatched and loss are counters, not places, so they are out.
     *
     * @param  array<int, array{operation_id: int, department_id: int}>  $steps
     * @return list<array{key: string, name: string, qty: float}>
     */
    private function wipByStage(array $steps, ?Department $department): array
    {
        $rows = DB::table('lot_balances')
            ->join('lots', 'lots.id', '=', 'lot_balances.lot_id')
            ->whereIn('lots.state', ['open', 'hold'])
            ->where('lot_balances.qty', '>', 0)
            ->groupBy('lot_balances.position')
            ->selectRaw('lot_balances.position as p, sum(lot_balances.qty) as q')
            ->get();

        $fixed = [Position::STOCK => 0.0, Position::ISSUED => 0.0, Position::FINISHED => 0.0];
        /** @var array<int, float> $perDepartment */
        $perDepartment = [];

        foreach ($rows as $row) {
            $position = Position::from((string) $row->p);
            $qty = (float) $row->q;
            $stepId = $position->stepId();
            if ($stepId === null) {
                if (array_key_exists($position->value(), $fixed)) {
                    $fixed[$position->value()] += $qty;
                }

                continue;
            }
            $owner = $steps[$stepId] ?? null;
            if ($owner !== null) {
                $perDepartment[$owner['department_id']] = ($perDepartment[$owner['department_id']] ?? 0.0) + $qty;
            }
        }

        $stages = [];
        if ($department === null) {
            $stages[] = ['key' => 'stock', 'name' => $this->line('dashboard.stages.stock'), 'qty' => $this->round($fixed[Position::STOCK])];
            $stages[] = ['key' => 'issued', 'name' => $this->line('dashboard.stages.issued'), 'qty' => $this->round($fixed[Position::ISSUED])];
        }
        foreach ($this->departments($department) as $d) {
            $stages[] = [
                'key' => 'department:'.$d->getKey(),
                'name' => $this->departmentName($d),
                'qty' => $this->round($perDepartment[(int) $d->getKey()] ?? 0.0),
            ];
        }
        if ($department === null) {
            $stages[] = ['key' => 'finished', 'name' => $this->line('dashboard.stages.finished'), 'qty' => $this->round($fixed[Position::FINISHED])];
        }

        return $stages;
    }

    // ---- lookups ---------------------------------------------------------------------------------------

    /**
     * Every route step's operation and department, in one query. `lot_route_steps` is small next to
     * `lot_movements` and a step is referenced by many movements, so this is the cheap side of the join.
     *
     * @return array<int, array{operation_id: int, department_id: int}>
     */
    private function stepOwners(): array
    {
        $rows = DB::table('lot_route_steps')
            ->join('operations', 'operations.id', '=', 'lot_route_steps.operation_id')
            ->select('lot_route_steps.id', 'operations.id as operation_id', 'operations.department_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->id] = ['operation_id' => (int) $row->operation_id, 'department_id' => (int) $row->department_id];
        }

        return $map;
    }

    /** @return Collection<int, Department> */
    private function departments(?Department $only)
    {
        if ($only !== null) {
            return collect([$only]);
        }

        return Department::query()->where('is_active', true)->orderBy('sort')->orderBy('code')->get();
    }

    /** @return list<array{id: int, code: string, name: string}> */
    private function departmentOptions(): array
    {
        return array_values($this->departments(null)
            ->map(fn (Department $d) => ['id' => (int) $d->getKey(), 'code' => $d->code, 'name' => $this->departmentName($d)])
            ->all());
    }

    /** @return list<array{id: int, name: string, department_id: int}> */
    private function operationOptions(): array
    {
        return array_values(Operation::query()->where('is_active', true)->orderBy('department_id')->orderBy('name')->get()
            ->map(fn (Operation $o) => [
                'id' => (int) $o->getKey(),
                'name' => $o->name,
                'department_id' => (int) $o->department_id,
            ])
            ->all());
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /** @return list<string> */
    private function dates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];
        for ($d = $from->startOfDay(); $d->lessThanOrEqualTo($to->startOfDay()); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }

    /** MySQL's `date()` yields 'Y-m-d'; SQLite's can carry a time when the column holds one. */
    private function dayOf(string $value): string
    {
        return substr($value, 0, 10);
    }

    /** @param list<array{id: int|null, key: string, name: string, total: float, data: list<float>}> $series */
    private function totalOf(array $series, string $key): float
    {
        foreach ($series as $one) {
            if ($one['key'] === $key) {
                return $one['total'];
            }
        }

        return 0.0;
    }

    private function departmentName(Department $d): string
    {
        $name = $d->getAttribute('name');

        return is_string($name) ? $name : $d->code;
    }

    private function round(float $value): float
    {
        return round($value, 2);
    }

    /** A lang line as a plain string: __() is declared as string|array|null, which no caller here wants. */
    private function line(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }
}
