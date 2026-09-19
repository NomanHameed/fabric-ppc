<?php

namespace Database\Factories\Modules\Foundation;

use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Operation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Operation> */
class OperationFactory extends Factory
{
    protected $model = Operation::class;

    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => strtoupper($this->faker->unique()->words(2, true)),
            'kind' => 'normal',
            'stage_label' => 'TEST/',
        ];
    }
}
