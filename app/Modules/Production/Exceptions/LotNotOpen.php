<?php

namespace App\Modules\Production\Exceptions;

use App\Modules\Production\Models\Lot;
use DomainException;

/** Floor movements (issue, process, reprocess) need a lot whose state is `open`. */
final class LotNotOpen extends DomainException
{
    public function __construct(public readonly Lot $lot)
    {
        parent::__construct(__('production.errors.lot_not_open', [
            'lot' => $lot->lot_no,
            'state' => __('production.states.'.$lot->state),
        ]));
    }
}
