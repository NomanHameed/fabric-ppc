<?php

namespace Database\Factories\Modules\Foundation;

use App\Modules\Foundation\Models\Machine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Machine> */
class MachineFactory extends Factory
{
    protected $model = Machine::class;

    public function definition(): array
    {
        return ['code' => strtoupper($this->faker->unique()->bothify('M-###')), 'name' => $this->faker->words(2, true)];
    }
}
