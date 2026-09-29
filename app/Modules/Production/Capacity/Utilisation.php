<?php

namespace App\Modules\Production\Capacity;

/**
 * Allocated versus available capacity for one machine (or one department) over a date range.
 *
 * `capacityQty` is null when nobody has entered a capacity for the machine: utilisation is then *unknown*, and
 * is reported as null rather than as 0% or 100%, both of which would be a made-up number on a dashboard.
 */
final readonly class Utilisation
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public float $allocatedQty,
        public ?float $capacityQty,
        public int $days,
        public string $uom,
    ) {}

    /** 0–∞ percent, or null when the machine has no capacity on file. Over 100 is real: it means over-committed. */
    public function percent(): ?float
    {
        $capacity = $this->knownCapacity();

        if ($capacity === null) {
            return null;
        }

        return round($this->allocatedQty / $capacity * 100, 1);
    }

    /**
     * Metres still uncommitted; null when capacity is unknown. Negative means over-allocated.
     *
     * The same `<= 0` rule as percent(): a machine whose capacity is recorded as zero has no usable figure, so
     * "500 m free of nothing" is as made up as "0% used". The three fields have to agree — a row that said
     * `capacity_qty: 0, free_qty: -500, utilisation_pct: null` made the screen contradict itself.
     */
    public function freeQty(): ?float
    {
        return $this->knownCapacity() === null ? null : round($this->knownCapacity() - $this->allocatedQty, 2);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $capacity = $this->knownCapacity();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'allocated_qty' => round($this->allocatedQty, 2),
            'capacity_qty' => $capacity === null ? null : round($capacity, 2),
            'free_qty' => $this->freeQty(),
            'utilisation_pct' => $this->percent(),
            'days' => $this->days,
            'uom' => $this->uom,
        ];
    }

    /** The capacity figure when there is a usable one: not null, and not zero or less. */
    private function knownCapacity(): ?float
    {
        return $this->capacityQty === null || $this->capacityQty <= 0.0 ? null : $this->capacityQty;
    }
}
