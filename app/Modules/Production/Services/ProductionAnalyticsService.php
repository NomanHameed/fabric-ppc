<?php

namespace App\Modules\Production\Services;

use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Query\QueryCriteria;
use App\Modules\Production\Analytics\DashboardData;
use App\Modules\Production\Capacity\Utilisation;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\LotMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

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
 *
 * `flatLots()` at the bottom is the one place the **flat, Oracle-shaped lot payload** is assembled, for both
 * the Lots screen and the fabric report.
 *
 * @phpstan-type FlatLotRow array{
 *     party_oracle_id: int|null,
 *     party_oracle_name: string,
 *     po_no: int,
 *     ref: string|null,
 *     design_oracle_id: string|null,
 *     color_oracle_id: string|null,
 *     color_oracle_name: string|null,
 *     clr_status_oracle: string|null,
 *     po_type_oracle: string,
 *     date_oracle: string|null,
 *     lot_no_oracle: string,
 *     quality: string,
 *     gw_oracle: float|null,
 *     status_oracle: string|null,
 *     greige_received_oracle: float,
 *     greige_in_stock_oracle: float,
 *     qty_issued: float,
 *     wip: float,
 *     finished: float,
 *     dispatch: float,
 *     stage_id: int|null,
 *     stage_name: string|null,
 *     stage_process_id: int|null,
 *     stage_process_name: string|null,
 *     lot_id: int,
 * }
 */
final class ProductionAnalyticsService
{
    /**
     * The flat payload's keys, in the order `flatLots()` emits them — the mill's own field names, lower
     * snake-cased. The first 21 are the user's list (`color_*` keeps their spelling even though the schema
     * says `colour_*`: the schema is deliberately left alone, the payload is a projection of it). The last
     * four are additive, because the report needs the stage names and the UI needs the real primary key to
     * link to the Lot Inquiry with.
     *
     * @var list<string>
     */
    public const FLAT_KEYS = [
        'party_oracle_id', 'party_oracle_name', 'po_no', 'ref', 'design_oracle_id', 'color_oracle_id',
        'color_oracle_name', 'clr_status_oracle', 'po_type_oracle', 'date_oracle', 'lot_no_oracle', 'quality',
        'gw_oracle', 'status_oracle', 'greige_received_oracle', 'greige_in_stock_oracle', 'qty_issued', 'wip',
        'finished', 'dispatch', 'stage_id', 'stage_name', 'stage_process_id', 'stage_process_name', 'lot_id',
    ];

    /**
     * The flat payload's key => source column, which is also the `select` list `flatLots()` runs. Kept as one
     * map so the payload's shape, its SQL projection and the key order above can never drift apart.
     *
     * @var array<string, string>
     */
    private const FLAT_COLUMNS = [
        'party_oracle_id' => 'parties.oracle_code',
        'party_oracle_name' => 'parties.name',
        'po_no' => 'contracts.po_no',
        'ref' => 'contracts.ref_no',
        'design_oracle_id' => 'lots.design_code',
        'color_oracle_id' => 'lots.colour_code',
        'color_oracle_name' => 'lots.colour',
        'clr_status_oracle' => 'lots.colour_status',
        'po_type_oracle' => 'lots.po_type',
        'date_oracle' => 'contracts.po_date',
        'lot_no_oracle' => 'lots.lot_no',
        'quality' => 'qualities.description',
        'gw_oracle' => 'lots.g_width',
        'status_oracle' => 'lots.current_label',
        'greige_received_oracle' => 'lots.greige_qty',
        'greige_in_stock_oracle' => 'lots.stock_qty',
        'qty_issued' => 'lots.issued_qty',
        'wip' => 'lots.wip_qty',
        'finished' => 'lots.finished_qty',
        'dispatch' => 'lots.dispatched_qty',
        'stage_id' => 'lots.stage_id',
        'stage_name' => 'stages.name',
        'stage_process_id' => 'lots.stage_process_id',
        'stage_process_name' => 'stage_processes.name',
        'lot_id' => 'lots.id',
    ];

    /**
     * The Lots screen's query-mode fields (spec §4.2), over the lot and its joined PO, party, quality and
     * stage. It lives here rather than on LotController because `flatLots()` applies the same map, so query
     * mode narrows the flat shape exactly as it narrows the nested one.
     *
     * @var array<string, array{column: string, type: string}>
     */
    public const LOT_CRITERIA = [
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
        'stage' => ['column' => 'stages.name', 'type' => 'text'],
        'stage_process' => ['column' => 'stage_processes.name', 'type' => 'text'],
        'po_date' => ['column' => 'contracts.po_date', 'type' => 'date'],
    ];

    /** Ledger kinds that represent work done on the floor. */
    private const PRODUCTION_KINDS = ['process', 'reprocess'];

    /** The report's six quantities, as they are stored on the lot (spec §14). */
    private const QTY_COLUMNS = ['greige_qty', 'stock_qty', 'issued_qty', 'wip_qty', 'finished_qty', 'dispatched_qty'];

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
     * Why `DB::table('lot_balances')` and not `LedgerService::balances()`: that method answers "the positions
     * of *one* lot" (`LedgerService::balances(Lot $lot)`); this needs one grouped SUM over every open lot, and
     * calling it per lot would be a query per lot on every dashboard refresh. It is a read — the ledger's
     * authority is over *writes*, and LedgerService remains the only writer of `lot_movements` and
     * `lot_balances`, so nothing here can make the two disagree. If `balances()` ever grows a grouped,
     * cross-lot form, this should move onto it.
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

    /**
     * The mill's lots grouped **stage -> stage process**, with the report's six quantities and a lot count
     * per row (spec §13, §14). This is the grouping the Month Wise Fabric Report reads; the dashboard's
     * `wipByStage()` above stays as it is, because it answers a different question (metres per *department*,
     * out of the balances).
     *
     * **No double counting, by construction.** `lots.stage_id` / `lots.stage_process_id` say where the lot
     * *is now* — one lot, one process — so a lot contributes its quantities to exactly one row. There is no
     * stage history to sum over and none is invented here: the figures are the lot's own derived totals,
     * summed in the database, so the stage split of each quantity always adds back up to the mill total.
     *
     * A lot whose status no process claims (and a lot still entirely in stock, which carries no status at
     * all) lands in the `unclassified` row rather than being dropped, so the rows always total the mill. The
     * Unmapped Statuses screen is where those statuses are named.
     *
     * @param  list<string>|null  $states  lot states to include; null means every state
     * @return list<array{id: int|null, code: string, name: string, lots: int, greige_qty: float, stock_qty: float, issued_qty: float, wip_qty: float, finished_qty: float, dispatched_qty: float, processes: list<array{id: int|null, code: string, name: string, lots: int, greige_qty: float, stock_qty: float, issued_qty: float, wip_qty: float, finished_qty: float, dispatched_qty: float}>}>
     */
    public function byStage(?array $states = ['open', 'hold']): array
    {
        $rows = DB::table('lots')
            ->when($states !== null, fn ($q) => $q->whereIn('lots.state', $states ?? []))
            ->groupBy('lots.stage_id', 'lots.stage_process_id')
            ->selectRaw('lots.stage_id, lots.stage_process_id, count(*) as lots, '
                .implode(', ', array_map(fn (string $c): string => "coalesce(sum(lots.{$c}), 0) as {$c}", self::QTY_COLUMNS)))
            ->get();

        $stages = Stage::query()->with('processes')->orderBy('sort_order')->orderBy('code')->get();
        /** @var array<string, object> $byKey stage id . ':' . process id => the grouped row */
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[((int) $row->stage_id).':'.((int) $row->stage_process_id)] = $row;
        }

        $out = [];
        foreach ($stages as $stage) {
            $processes = [];
            foreach ($stage->processes as $process) {
                $key = ((int) $stage->getKey()).':'.((int) $process->getKey());
                $processes[] = ['id' => (int) $process->getKey(), 'code' => $process->code, 'name' => $process->name]
                    + $this->stageQuantities($byKey[$key] ?? null);
                unset($byKey[$key]);
            }
            $out[] = ['id' => (int) $stage->getKey(), 'code' => $stage->code, 'name' => $stage->name]
                + $this->sumRows($processes) + ['processes' => $processes];
        }

        // Whatever no row above claimed: lots with no stage, lots still entirely in stock (no status at all)
        // and the odd lot left pointing at a process that is no longer in the taxonomy. Summed here rather
        // than dropped, so the rows always add up to the mill.
        $unclassified = ['id' => null, 'code' => 'UNCLASSIFIED', 'name' => $this->line('dashboard.stages.unclassified')]
            + $this->sumRows(array_map(fn (object $row): array => $this->stageQuantities($row), array_values($byKey)));
        $out[] = $unclassified + ['processes' => [$unclassified]];

        return $out;
    }

    // ---- the flat, Oracle-shaped lot payload -----------------------------------------------------------

    /**
     * The flat lot payload: one row per lot, 25 keys in `FLAT_KEYS` order, named the way the mill names its
     * own fields rather than the way the schema does.
     *
     * **The schema is untouched on purpose.** `colour_code`, `colour`, `colour_status`, `g_width`,
     * `greige_qty`, `stock_qty`, `issued_qty`, `wip_qty`, `finished_qty`, `dispatched_qty` and
     * `current_label` keep their names in the database; this method is the projection that renames them, so
     * no migration, rename or drop is needed and every existing reader (`LotScreenRow`, the inquiry payloads,
     * the ledger) keeps working untouched.
     *
     * **One query, always.** The party, PO, quality, stage and stage process come from joins in the same
     * statement — never from lazy relations — so a page of rows costs exactly one query however many rows it
     * holds. `stages` and `stage_processes` are left-joined: a lot whose status no process claims (and a lot
     * still entirely in stock, which carries no status at all) has no stage, and an inner join would drop it.
     *
     * Quantities come back as floats, not the driver's `decimal` strings, so a grid can right-align and total
     * them without parsing. A null stays null — an unset greige width or an unresolved stage reads as absent,
     * never as `0` or `''`, because "not measured" and "zero metres" are different facts.
     *
     * @param  array<string, mixed>  $criteria  query-mode criteria, parsed by `QueryCriteria` over `LOT_CRITERIA`
     * @param  list<string>|null  $states  lot states to include; null means every state
     * @param  int|null  $limit  rows at most; null for all of them
     * @return list<FlatLotRow>
     */
    public function flatLots(array $criteria = [], ?array $states = null, ?int $limit = null): array
    {
        $query = DB::table('lots')
            ->join('contracts', 'contracts.id', '=', 'lots.contract_id')
            ->join('parties', 'parties.id', '=', 'contracts.party_id')
            ->join('qualities', 'qualities.id', '=', 'lots.quality_id')
            ->leftJoin('stages', 'stages.id', '=', 'lots.stage_id')
            ->leftJoin('stage_processes', 'stage_processes.id', '=', 'lots.stage_process_id')
            ->select(array_map(
                fn (string $key): string => self::FLAT_COLUMNS[$key].' as '.$key,
                self::FLAT_KEYS,
            ));

        QueryCriteria::apply($query, $criteria, self::LOT_CRITERIA);
        if ($states !== null) {
            $query->whereIn('lots.state', $states);
        }
        $query->orderByDesc('contracts.po_date')->orderBy('contracts.po_no')
            ->orderBy('lots.lot_no')->orderBy('lots.id');
        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query->get()->map(fn (stdClass $row): array => $this->flatRow($row))->all());
    }

    /**
     * How many lots the same criteria match, for the screen's "showing n of m". Its own query by design:
     * `flatLots()` stays one statement, and the count is only wanted where a cap can hide rows.
     *
     * @param  array<string, mixed>  $criteria
     * @param  list<string>|null  $states
     */
    public function flatLotsCount(array $criteria = [], ?array $states = null): int
    {
        $query = DB::table('lots')
            ->join('contracts', 'contracts.id', '=', 'lots.contract_id')
            ->join('parties', 'parties.id', '=', 'contracts.party_id')
            ->join('qualities', 'qualities.id', '=', 'lots.quality_id')
            ->leftJoin('stages', 'stages.id', '=', 'lots.stage_id')
            ->leftJoin('stage_processes', 'stage_processes.id', '=', 'lots.stage_process_id');

        QueryCriteria::apply($query, $criteria, self::LOT_CRITERIA);
        if ($states !== null) {
            $query->whereIn('lots.state', $states);
        }

        return $query->count();
    }

    /**
     * The fabric report with its lots listed flat under each process.
     *
     * The **grouping is unchanged**: stage → stage process, exactly as `byStage()` returns it, with the same
     * totals and the same `unclassified` row. The only addition is a `rows` list on each process, holding the
     * flat payloads of the lots that process claims; a lot no process claims lands under `unclassified`'s own
     * pseudo-process, the same place its quantities already go, so the rows under the report always add up to
     * the rows in it.
     *
     * The lots are read with **one** `flatLots()` query and bucketed in PHP, not queried per process.
     *
     * @param  list<string>|null  $states  lot states to include; null means every state
     * @param  int|null  $limit  flat rows at most, across the whole report
     * @return array{stages: list<array<string, mixed>>, rows_total: int}
     */
    public function fabricReport(?array $states = ['open', 'hold'], ?int $limit = null): array
    {
        $stages = $this->byStage($states);
        $rows = $this->flatLots([], $states, $limit);

        /** @var array<string, list<FlatLotRow>> $byKey stage id . ':' . process id => its lots */
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[((int) $row['stage_id']).':'.((int) $row['stage_process_id'])][] = $row;
        }

        $out = [];
        $unclassified = null;
        foreach ($stages as $i => $stage) {
            $out[$i] = $stage;
            if ($stage['id'] === null) {
                $unclassified = $i; // filled after the loop, from whatever no real process claimed

                continue;
            }
            $processes = [];
            foreach ($stage['processes'] as $process) {
                $key = ((int) $stage['id']).':'.((int) $process['id']);
                $processes[] = array_merge($process, ['rows' => $byKey[$key] ?? []]);
                unset($byKey[$key]);
            }
            $out[$i] = array_merge($stage, ['processes' => $processes]);
        }

        // The unclassified row's pseudo-process: a lot with no stage at all, and a lot still pointing at a
        // process that has left the taxonomy. Exactly where `byStage()` already puts their quantities.
        if ($unclassified !== null) {
            $leftovers = [];
            foreach ($byKey as $bucket) {
                foreach ($bucket as $row) {
                    $leftovers[] = $row;
                }
            }
            $stage = $out[$unclassified];
            $out[$unclassified] = array_merge($stage, ['processes' => array_map(
                fn (array $process): array => array_merge($process, ['rows' => $leftovers]),
                $stage['processes'],
            )]);
        }

        return ['stages' => array_values($out), 'rows_total' => count($rows)];
    }

    /**
     * One database row as the flat payload. Written out key by key rather than looped, because the key order
     * is part of the contract the report and the grid read.
     *
     * @return FlatLotRow
     */
    private function flatRow(stdClass $row): array
    {
        $text = fn (string $key): ?string => $row->{$key} === null ? null : (string) $row->{$key};
        $qty = fn (string $key): float => $this->round((float) $row->{$key});

        /** @var FlatLotRow $out */
        $out = [
            'party_oracle_id' => $row->party_oracle_id === null ? null : (int) $row->party_oracle_id,
            'party_oracle_name' => (string) $row->party_oracle_name,
            'po_no' => (int) $row->po_no,
            'ref' => $text('ref'),
            'design_oracle_id' => $text('design_oracle_id'),
            'color_oracle_id' => $text('color_oracle_id'),
            'color_oracle_name' => $text('color_oracle_name'),
            'clr_status_oracle' => $text('clr_status_oracle'),
            'po_type_oracle' => (string) $row->po_type_oracle,
            'date_oracle' => $row->date_oracle === null
                ? null
                : CarbonImmutable::parse((string) $row->date_oracle)->format('d-m-Y'),
            'lot_no_oracle' => (string) $row->lot_no_oracle,
            'quality' => (string) $row->quality,
            'gw_oracle' => $row->gw_oracle === null ? null : $this->round((float) $row->gw_oracle),
            'status_oracle' => $text('status_oracle'),
            'greige_received_oracle' => $qty('greige_received_oracle'),
            'greige_in_stock_oracle' => $qty('greige_in_stock_oracle'),
            'qty_issued' => $qty('qty_issued'),
            'wip' => $qty('wip'),
            'finished' => $qty('finished'),
            'dispatch' => $qty('dispatch'),
            'stage_id' => $row->stage_id === null ? null : (int) $row->stage_id,
            'stage_name' => $text('stage_name'),
            'stage_process_id' => $row->stage_process_id === null ? null : (int) $row->stage_process_id,
            'stage_process_name' => $text('stage_process_name'),
            'lot_id' => (int) $row->lot_id,
        ];

        return $out;
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

    /**
     * One grouped row's lot count and quantities; all zero when no lot sits there (the row is still shown,
     * so the report's shape does not change with the data).
     *
     * @return array{lots: int, greige_qty: float, stock_qty: float, issued_qty: float, wip_qty: float, finished_qty: float, dispatched_qty: float}
     */
    private function stageQuantities(?object $row): array
    {
        $out = ['lots' => $row === null ? 0 : (int) ($row->lots ?? 0)];
        foreach (self::QTY_COLUMNS as $column) {
            $out[$column] = $this->round($row === null ? 0.0 : (float) ($row->{$column} ?? 0));
        }

        /** @var array{lots: int, greige_qty: float, stock_qty: float, issued_qty: float, wip_qty: float, finished_qty: float, dispatched_qty: float} */
        return $out;
    }

    /**
     * A stage's own figures: the sum of its processes. Not a second query — and not a separate grouping
     * either, so a stage total can never disagree with the rows under it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{lots: int, greige_qty: float, stock_qty: float, issued_qty: float, wip_qty: float, finished_qty: float, dispatched_qty: float}
     */
    private function sumRows(array $rows): array
    {
        $out = ['lots' => 0];
        foreach (self::QTY_COLUMNS as $column) {
            $out[$column] = 0.0;
        }
        foreach ($rows as $row) {
            $out['lots'] += (int) $row['lots'];
            foreach (self::QTY_COLUMNS as $column) {
                $out[$column] = $this->round($out[$column] + (float) $row[$column]);
            }
        }

        /** @var array{lots: int, greige_qty: float, stock_qty: float, issued_qty: float, wip_qty: float, finished_qty: float, dispatched_qty: float} */
        return $out;
    }

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
