<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Activity\Concerns\RecordsUserActions;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Production\Capacity\Utilisation;
use App\Modules\Production\Models\MachineAllocation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Allocating machine capacity, and reading utilisation back out for the dashboard.
 *
 * Capacity for a range is the machine's daily figure times the number of days in the range (inclusive), so a
 * seven-day view of a 1 000 m/day machine has 7 000 m of capacity. Allocation is the sum of machine_allocations
 * rows in the range. Neither figure reads or writes the ledger.
 */
final class MachineCapacityService
{
    use RecordsUserActions;

    /**
     * Columns a caller may set through `$extra`. Everything else on the row — the machine, the operation, the
     * department, the date, the quantity and the unit — is computed here and must not be overridable: PHP's
     * `+` keeps the *left* operand, so `$extra + [...]` would have let a caller quietly replace the rounded
     * quantity or point the row at another machine.
     *
     * @var list<string>
     */
    public const EXTRA_COLUMNS = ['shift_id', 'lot_id', 'note'];

    /**
     * Commit metres of a machine to an operation on a date. The department is taken from the operation, or
     * from the machine's first department when no operation is given — it is denormalised so that department
     * utilisation stays one query.
     *
     * @param  array<string, mixed>  $extra  extra columns; only EXTRA_COLUMNS are accepted, the rest are ignored
     */
    public function allocate(
        Machine $machine,
        CarbonInterface $date,
        float $qty,
        ?Operation $operation = null,
        ?Department $department = null,
        array $extra = [],
        ?User $by = null,
    ): MachineAllocation {
        if ($qty <= 0) {
            throw new InvalidArgumentException('An allocation needs a positive quantity.');
        }

        $department ??= $operation?->department;
        $department ??= Department::query()
            ->whereHas('machines', fn ($q) => $q->whereKey($machine->getKey()))
            ->orderBy('sort')->first();

        if ($department === null) {
            throw new InvalidArgumentException("Machine {$machine->code} belongs to no department; pass one explicitly.");
        }

        $allocation = MachineAllocation::query()->create(array_intersect_key($extra, array_flip(self::EXTRA_COLUMNS)) + [
            'machine_id' => $machine->getKey(),
            'operation_id' => $operation?->getKey(),
            'department_id' => $department->getKey(),
            'allocated_on' => $date->toDateString(),
            'allocated_qty' => round($qty, 2),
            'uom' => $machine->capacity_uom ?: Machine::UOM_METRES,
            'created_by' => $by?->getKey(),
        ]);

        $this->recordAction(ActionKeys::CAPACITY_ALLOCATED, $allocation, [
            'qty' => round($qty, 2), 'machine' => $machine->code, 'date' => $date->toDateString(),
        ], $by);

        return $allocation;
    }

    /** Allocated versus capacity for one machine over an inclusive date range. */
    public function forMachine(Machine $machine, CarbonInterface $from, CarbonInterface $to): Utilisation
    {
        $days = $this->days($from, $to);
        $daily = $machine->dailyCapacityQty();

        $allocated = (float) MachineAllocation::query()
            ->where('machine_id', $machine->getKey())
            ->between($from->toDateString(), $to->toDateString())
            ->sum('allocated_qty');

        return new Utilisation(
            id: (int) $machine->getKey(),
            code: $machine->code,
            name: $machine->name,
            allocatedQty: $allocated,
            capacityQty: $daily === null ? null : $daily * $days,
            days: $days,
            uom: $machine->capacity_uom ?: Machine::UOM_METRES,
        );
    }

    /**
     * One row per machine, optionally narrowed to a department (by the machine's department links).
     *
     * @return Collection<int, Utilisation>
     */
    public function byMachine(CarbonInterface $from, CarbonInterface $to, ?Department $department = null): Collection
    {
        $days = $this->days($from, $to);

        $machines = Machine::query()->where('is_active', true)
            ->when($department, fn ($q, Department $d) => $q->whereHas('departments', fn ($q) => $q->whereKey($d->getKey())))
            ->orderBy('code')->get();

        $allocated = MachineAllocation::query()
            ->between($from->toDateString(), $to->toDateString())
            ->whereIn('machine_id', $machines->modelKeys())
            ->when($department, fn ($q, Department $d) => $q->where('department_id', $d->getKey()))
            ->selectRaw('machine_id, sum(allocated_qty) as total')
            ->groupBy('machine_id')->pluck('total', 'machine_id');

        return $machines->map(function (Machine $m) use ($allocated, $days): Utilisation {
            $daily = $m->dailyCapacityQty();

            return new Utilisation(
                id: (int) $m->getKey(),
                code: $m->code,
                name: $m->name,
                allocatedQty: (float) ($allocated[$m->getKey()] ?? 0),
                capacityQty: $daily === null ? null : $daily * $days,
                days: $days,
                uom: $m->capacity_uom ?: Machine::UOM_METRES,
            );
        })->values();
    }

    /**
     * A department's total: its machines' capacity against everything allocated to that department in the
     * range. Capacity is null when *no* machine in the department has a figure on file.
     *
     * @param  Collection<int, Utilisation>|null  $rows  this department's machine rows, when already computed
     */
    public function forDepartment(Department $department, CarbonInterface $from, CarbonInterface $to, ?Collection $rows = null): Utilisation
    {
        // The caller often has the machine rows already (the utilisation endpoint lists them beside this
        // total); taking them saves repeating the machine SELECT and the grouped SUM on every chart refresh.
        $rows ??= $this->byMachine($from, $to, $department);
        $known = $rows->filter(fn (Utilisation $u) => $u->capacityQty !== null);

        // `name` is an accessor (en/ur), so it comes back as mixed; the code is a safe stand-in if it ever is not a string.
        $name = $department->getAttribute('name');

        return new Utilisation(
            id: (int) $department->getKey(),
            code: $department->code,
            name: is_string($name) ? $name : $department->code,
            allocatedQty: (float) $rows->sum(fn (Utilisation $u) => $u->allocatedQty),
            capacityQty: $known->isEmpty() ? null : (float) $known->sum(fn (Utilisation $u) => $u->capacityQty),
            days: $this->days($from, $to),
            uom: Machine::UOM_METRES,
        );
    }

    /**
     * One row per department, in three queries rather than one `forDepartment()` call each.
     *
     * A machine linked to two departments contributes its capacity to both — the same arithmetic
     * `forDepartment()` already does, kept identical so the two never disagree on a dashboard.
     *
     * @return Collection<int, Utilisation>
     */
    public function byDepartment(CarbonInterface $from, CarbonInterface $to, ?Department $only = null): Collection
    {
        $days = $this->days($from, $to);

        $departments = Department::query()->where('is_active', true)
            ->when($only, fn ($q, Department $d) => $q->whereKey($d->getKey()))
            ->orderBy('sort')->orderBy('code')->get();

        $capacities = Machine::query()->where('is_active', true)->get()
            ->mapWithKeys(fn (Machine $m) => [(int) $m->getKey() => $m->dailyCapacityQty()]);

        $links = DB::table('department_machine')
            ->whereIn('department_id', $departments->modelKeys())
            ->whereIn('machine_id', $capacities->keys()->all())
            ->get();

        $allocated = MachineAllocation::query()
            ->between($from->toDateString(), $to->toDateString())
            ->whereIn('department_id', $departments->modelKeys())
            ->selectRaw('department_id, sum(allocated_qty) as total')
            ->groupBy('department_id')->pluck('total', 'department_id');

        return $departments->map(function (Department $d) use ($links, $capacities, $allocated, $days): Utilisation {
            $known = null;
            foreach ($links as $link) {
                if ((int) $link->department_id !== (int) $d->getKey()) {
                    continue;
                }
                $daily = $capacities[(int) $link->machine_id] ?? null;
                if ($daily !== null) {
                    $known = ($known ?? 0.0) + $daily * $days;
                }
            }

            $name = $d->getAttribute('name');

            return new Utilisation(
                id: (int) $d->getKey(),
                code: $d->code,
                name: is_string($name) ? $name : $d->code,
                allocatedQty: (float) ($allocated[$d->getKey()] ?? 0),
                capacityQty: $known,
                days: $days,
                uom: Machine::UOM_METRES,
            );
        })->values();
    }

    /**
     * Inclusive **calendar** day count; a single date is one day, not zero.
     *
     * Calendar days, not working days, and deliberately so: the application has no working calendar. There is
     * no roster, no shift pattern per machine, no holiday master — nothing that could say which days a given
     * machine was meant to run. Inventing one here (say, "Sundays don't count", which is what the demo data
     * happens to do) would be this service making up a business rule nobody has stated, and it would move
     * every utilisation figure on the dashboard by roughly a seventh.
     *
     * The consequence is real and must be read the right way: over a month that includes four idle Sundays,
     * capacity is counted for those Sundays too, so utilisation reads about 14% lower than "of the days we
     * actually ran". The screen says so — `production.capacity.range` reads "(:days calendar day(s))".
     *
     * If PPC wants utilisation against *working* days, the fix is a working-calendar master (per machine or
     * per department, with holidays), not arithmetic here. Until that exists, this stays as it is.
     */
    private function days(CarbonInterface $from, CarbonInterface $to): int
    {
        if ($to->startOfDay()->lessThan($from->startOfDay())) {
            throw new InvalidArgumentException('The range ends before it starts.');
        }

        return (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
    }
}
