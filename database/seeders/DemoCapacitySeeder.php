<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Shift;
use App\Modules\Production\Models\MachineAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Machine capacities and a month of allocations, so the utilisation figures the dashboard reads are not zero.
 *
 * Capacity is in metres. Most machines get a daily figure; two get an hourly one instead, which is what the
 * "hourly and/or daily" rule exists for — MachineCapacityService reads the hourly figure over a 24-hour mill
 * day when there is no daily one.
 *
 * Deterministic: the allocated fraction of each machine's capacity is a function of the machine index and the
 * day offset, so the utilisation chart has a stable, varied shape.
 */
class DemoCapacitySeeder extends Seeder
{
    public const DAYS = 30;

    /** machine code => [daily capacity, hourly capacity] — exactly one of the two per machine. */
    public const CAPACITIES = [
        'STENTER-1' => [24000, null],
        'STENTER-2' => [21000, null],
        'SANFO' => [18000, null],
        'DRYING-1' => [null, 600],
        'DRYING-2' => [null, 550],
        'JUMBO' => [15000, null],
        'SINGEING' => [26000, null],
        'MERCERIZER' => [16000, null],
        'DIGITAL-PRINTER' => [4800, null],
        'JIGGER' => [9000, null],
    ];

    public function run(): void
    {
        $machines = Machine::query()->whereIn('code', array_keys(self::CAPACITIES))->get();

        foreach ($machines as $machine) {
            // Never overwrite a real figure. These are the ten machines FoundationSeeder creates, so on an
            // install that has been in use they are the mill's actual machines with the mill's actual
            // capacities on them — and `db:seed` is documented as safe to re-run (docs/setup.md §6). A machine
            // that already carries a capacity is left exactly as it is.
            if ($machine->daily_capacity !== null || $machine->hourly_capacity !== null) {
                continue;
            }

            [$daily, $hourly] = self::CAPACITIES[$machine->code];
            $machine->forceFill([
                'daily_capacity' => $daily,
                'hourly_capacity' => $hourly,
                'capacity_uom' => Machine::UOM_METRES,
            ])->save();
        }

        if (MachineAllocation::query()->exists()) {
            return; // idempotent
        }

        $ppc = User::query()->where('email', DemoUsersSeeder::emailFor(Permissions::ROLE_PPC))->first();
        $shifts = Shift::query()->orderBy('code')->get();
        $today = CarbonImmutable::today();
        $rows = [];
        $m = 0;

        foreach ($machines as $machine) {
            // Queried through Department rather than $machine->departments(), which the analyser types loosely.
            $department = Department::query()
                ->whereHas('machines', fn ($q) => $q->whereKey($machine->getKey()))
                ->orderBy('sort')->first();
            if ($department === null) {
                continue;
            }
            $operations = Operation::query()->where('department_id', $department->id)
                ->where('is_active', true)->orderBy('id')->limit(3)->get();
            $capacity = $machine->dailyCapacityQty() ?? 1000.0;

            for ($d = 0; $d < self::DAYS; $d++) {
                // 45–95% of capacity, in a repeating but uneven pattern; Sundays are left unallocated.
                $date = $today->subDays(self::DAYS - 1 - $d);
                if ($date->isSunday()) {
                    continue;
                }
                $fraction = (45 + (($m * 7 + $d * 13) % 51)) / 100;
                $operation = $operations->isEmpty() ? null : $operations[($d + $m) % $operations->count()];

                $rows[] = [
                    'machine_id' => $machine->id,
                    'operation_id' => $operation?->id,
                    'department_id' => $department->id,
                    'shift_id' => $shifts->isEmpty() ? null : $shifts[($d + $m) % $shifts->count()]->id,
                    'lot_id' => null,
                    'allocated_on' => $date->toDateString(),
                    'allocated_qty' => round($capacity * $fraction, 2),
                    'uom' => Machine::UOM_METRES,
                    'note' => null,
                    'created_by' => $ppc?->id,
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
            }
            $m++;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            MachineAllocation::query()->insert($chunk);
        }
    }
}
