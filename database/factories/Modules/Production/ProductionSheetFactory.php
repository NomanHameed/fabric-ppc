<?php

namespace Database\Factories\Modules\Production;

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Shift;
use App\Modules\Production\Models\ProductionSheet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionSheet> */
class ProductionSheetFactory extends Factory
{
    protected $model = ProductionSheet::class;

    public function definition(): array
    {
        return [
            'sheet_date' => now()->toDateString(),
            'shift_id' => fn () => Shift::query()->value('id')
                ?? Shift::query()->create(['code' => 'A', 'name' => 'Shift A', 'starts_at' => '08:00', 'ends_at' => '20:00'])->id,
            'department_id' => Department::factory(),
            'machine_id' => fn (array $a) => tap(Machine::factory()->create(), fn (Machine $m) => $m->departments()->attach($a['department_id']))->id,
            'downtime_minutes' => 0,
            'entered_by' => User::factory(),
        ];
    }
}
