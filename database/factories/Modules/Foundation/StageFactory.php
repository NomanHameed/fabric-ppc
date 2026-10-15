<?php

namespace Database\Factories\Modules\Foundation;

use App\Modules\Foundation\Models\Stage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Stage> */
class StageFactory extends Factory
{
    protected $model = Stage::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('STAGE_????')),
            'name' => $this->faker->unique()->words(2, true),
            'sort_order' => $this->faker->numberBetween(1, 99),
            'is_active' => true,
        ];
    }
}
