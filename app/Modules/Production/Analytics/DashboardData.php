<?php

namespace App\Modules\Production\Analytics;

/**
 * Everything the dashboard draws, for one filter set.
 *
 * A single value object rather than a loose array so the controller, the tests and the TypeScript types on the
 * page all agree on one shape. Every quantity is metres, rounded to the ledger's two decimals.
 *
 * @phpstan-type Series array{id: int|null, key: string, name: string, total: float, data: list<float>}
 * @phpstan-type Stage array{key: string, name: string, qty: float}
 */
final readonly class DashboardData
{
    /**
     * @param  list<string>  $dates  the x axis, one entry per day in the range (Y-m-d)
     * @param  list<Series>  $byDepartment  metres finishing an operation, per department per day
     * @param  list<Series>  $flow  produced / issued / dispatched / loss per day
     * @param  list<Stage>  $wipByStage  where the mill's open metres sit right now
     * @param  array{produced: float, issued: float, dispatched: float, loss: float, wip: float}  $kpi
     * @param  array{machines: list<array<string, mixed>>, departments: list<array<string, mixed>>}|null  $utilisation
     *                                                                                                                  null when the viewer may not see capacity (production.sheet.view)
     * @param  array{departments: list<array{id: int, code: string, name: string}>, operations: list<array{id: int, name: string, department_id: int}>, applied: array{department_id: int|null, operation_id: int|null}}  $filters
     */
    public function __construct(
        public string $from,
        public string $to,
        public int $days,
        public array $dates,
        public array $byDepartment,
        public array $flow,
        public array $wipByStage,
        public array $kpi,
        public ?array $utilisation,
        public array $filters,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'range' => ['from' => $this->from, 'to' => $this->to, 'days' => $this->days],
            'dates' => $this->dates,
            'by_department' => $this->byDepartment,
            'flow' => $this->flow,
            'wip_by_stage' => $this->wipByStage,
            'kpi' => $this->kpi,
            'utilisation' => $this->utilisation,
            'filters' => $this->filters,
        ];
    }
}
