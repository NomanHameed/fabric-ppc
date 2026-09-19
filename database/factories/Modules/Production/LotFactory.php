<?php

namespace Database\Factories\Modules\Production;

use App\Modules\Foundation\Models\Quality;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lot> */
class LotFactory extends Factory
{
    protected $model = Lot::class;

    public function definition(): array
    {
        return [
            'oracle_line_id' => $this->faker->unique()->numberBetween(1, 999999),
            'lot_no' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'contract_id' => Contract::factory(),
            'quality_id' => fn () => Quality::fromOracle(
                $this->faker->unique()->numberBetween(1000, 9999),
                '88X60/30X30 100% VISCOSE [AJ]'
            )->id,
            'po_type' => 'DYED',
            'greige_qty' => 1000,
        ];
    }
}
