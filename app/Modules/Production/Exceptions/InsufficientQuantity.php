<?php

namespace App\Modules\Production\Exceptions;

use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use DomainException;

/** A movement would take more metres out of $position than the lot holds there (spec §5.3 "Validation"). */
final class InsufficientQuantity extends DomainException
{
    public function __construct(
        public readonly Lot $lot,
        public readonly Position $position,
        public readonly float $available,
        public readonly float $requested,
    ) {
        parent::__construct(__('production.errors.insufficient_quantity', [
            'lot' => $lot->lot_no,
            'position' => __('production.positions.'.$position->labelKey()),
            'available' => number_format($available, 2),
            'requested' => number_format($requested, 2),
        ]));
    }
}
