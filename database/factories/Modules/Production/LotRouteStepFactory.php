<?php

namespace Database\Factories\Modules\Production;

use App\Modules\Foundation\Models\Operation;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotRouteStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LotRouteStep> */
class LotRouteStepFactory extends Factory
{
    protected $model = LotRouteStep::class;

    public function definition(): array
    {
        return ['lot_id' => Lot::factory(), 'seq' => 10, 'operation_id' => Operation::factory(), 'is_added' => false, 'status' => 'pending'];
    }
}
