<?php

namespace Database\Factories\Modules\Foundation;

use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StageProcess> */
class StageProcessFactory extends Factory
{
    protected $model = StageProcess::class;

    public function definition(): array
    {
        return [
            'stage_id' => Stage::factory(),
            'code' => strtoupper($this->faker->unique()->lexify('PROC_????')),
            'name' => $this->faker->unique()->words(2, true),
            'sort_order' => $this->faker->numberBetween(1, 99),
            'quantity_type' => 'wip',
            'is_wip' => true,
            'is_finished' => false,
            'is_dispatch' => false,
            'status_aliases' => [],
            'is_active' => true,
        ];
    }
}
