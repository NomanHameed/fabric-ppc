<?php

namespace Database\Factories\Modules\Production;

use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Production\Models\MachineAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MachineAllocation> */
class MachineAllocationFactory extends Factory
{
    protected $model = MachineAllocation::class;

    public function definition(): array
    {
        return [
            'machine_id' => Machine::factory(),
            'department_id' => Department::factory(),
            'allocated_on' => now()->toDateString(),
            'allocated_qty' => 500,
            'uom' => Machine::UOM_METRES,
        ];
    }
}
