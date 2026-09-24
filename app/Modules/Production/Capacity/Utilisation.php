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
        if ($this->capacityQty === null || $this->capacityQty <= 0.0) {
            return null;
        }

        return round($this->allocatedQty / $this->capacityQty * 100, 1);
    }

    /** Metres still uncommitted; null when capacity is unknown. Negative means over-allocated. */
    public function freeQty(): ?float
    {
        return $this->capacityQty === null ? null : round($this->capacityQty - $this->allocatedQty, 2);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'allocated_qty' => round($this->allocatedQty, 2),
            'capacity_qty' => $this->capacityQty === null ? null : round($this->capacityQty, 2),
            'free_qty' => $this->freeQty(),
            'utilisation_pct' => $this->percent(),
            'days' => $this->days,
            'uom' => $this->uom,
        ];
    }
}
