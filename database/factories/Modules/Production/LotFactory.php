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

    /**
     * A lot with its Oracle fields, including the six denormalised copies of the PO, its party and the
     * quality.
     *
     * The copies are resolved **from the contract and quality this lot actually gets** (`configure()`, after
     * `make`), not invented, so a factory-made lot says the same thing a lot the import wrote would: the
     * report reads these columns, and a lot whose `party_oracle_name` disagreed with its contract's party
     * would make a test pass that production would fail. A caller that sets any of them by hand keeps its
     * own value.
     */
    public function definition(): array
    {
        return [
            'oracle_line_id' => $this->faker->unique()->numberBetween(1, 999999),
            'lot_no_oracle' => (string) $this->faker->unique()->numberBetween(100000, 999999),
            'contract_id' => Contract::factory(),
            'quality_id' => fn () => Quality::fromOracle(
                $this->faker->unique()->numberBetween(1000, 9999),
                '88X60/30X30 100% VISCOSE [AJ]'
            )->id,
            'po_type_oracle' => 'DYED',
            'greige_received_oracle' => 1000,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Lot $lot): void {
            $contract = Contract::query()->with('party')->find($lot->contract_id);
            $quality = Quality::query()->find($lot->quality_id);

            foreach ([
                'party_oracle_id' => fn () => $contract?->party->oracle_code,
                'party_oracle_name' => fn () => (string) ($contract === null ? 'UNKNOWN PARTY' : $contract->party->name),
                'po_no' => fn () => $contract?->po_no,
                'ref' => fn () => $contract?->ref_no,
                'date_oracle' => fn () => $contract?->po_date,
                'quality' => fn () => (string) ($quality === null ? 'UNKNOWN QUALITY' : $quality->description),
            ] as $column => $value) {
                if (! array_key_exists($column, $lot->getAttributes())) {
                    $lot->setAttribute($column, $value());
                }
            }
        });
    }
}
