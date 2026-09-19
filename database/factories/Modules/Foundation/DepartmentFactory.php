<?php

namespace Database\Factories\Modules\Foundation;

use App\Modules\Foundation\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->lexify('DEPT_????')),
            'name_en' => $this->faker->words(2, true),
            'sort' => $this->faker->numberBetween(1, 99),
        ];
    }
}
