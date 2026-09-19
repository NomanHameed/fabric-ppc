<?php

namespace Database\Factories\Modules\Production;

use App\Modules\Foundation\Models\Party;
use App\Modules\Production\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contract> */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'po_no' => $this->faker->unique()->numberBetween(260000, 269999),
            'party_id' => fn () => Party::create([
                'name' => $this->faker->unique()->company(),
                'type' => 'brand',
                'oracle_code' => $this->faker->unique()->numberBetween(1, 999999),
            ])->id,
            'po_date' => now(),
            'contract_qty' => 1000,
        ];
    }
}
