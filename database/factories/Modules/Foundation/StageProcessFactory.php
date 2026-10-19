<?php

namespace Database\Factories\Modules\Foundation;

use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One Oracle production line under a stage.
 *
 * `po_no` and `lot_no_oracle` are unique per generator, because the table's key is
 * `(stage_id, po_no, lot_no_oracle)` and a test that makes two rows must not collide on it.
 *
 * @extends Factory<StageProcess>
 */
class StageProcessFactory extends Factory
{
    protected $model = StageProcess::class;

    public function definition(): array
    {
        return [
            'stage_id' => Stage::factory(),
            'party_oracle_id' => $this->faker->numberBetween(1000, 9999),
            'party_oracle_name' => $this->faker->company(),
            'po_no' => $this->faker->unique()->numberBetween(100000, 999999),
            'ref' => strtoupper($this->faker->bothify('REF-####')),
            'design_oracle_id' => strtoupper($this->faker->bothify('D###')),
            'color_oracle_id' => strtoupper($this->faker->bothify('C###')),
            'color_oracle_name' => $this->faker->safeColorName(),
            'clr_status_oracle' => 'APPROVED',
            'po_type_oracle' => 'DYED',
            'date_oracle' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'lot_no_oracle' => (string) $this->faker->unique()->numberBetween(1, 99999),
            'quality' => $this->faker->words(3, true),
            'gw_oracle' => 63.50,
            'status_oracle' => 'DYEING/',
            'greige_received_oracle' => 1000.00,
            'greige_in_stock_oracle' => 250.00,
            'qty_issued' => 750.00,
            'wip' => 500.00,
            'finished' => 200.00,
            'dispatch' => 50.00,
        ];
    }
}
