<?php

namespace App\Modules\Production\Exceptions;

use App\Modules\Foundation\Models\Operation;
use App\Modules\Production\Models\Lot;
use DomainException;

/** The colour gate (spec §5.4): a gated operation on a lot whose colour status is not flagged approved, with no override. */
final class ColourNotApproved extends DomainException
{
    public function __construct(public readonly Lot $lot, public readonly Operation $operation)
    {
        parent::__construct(__('production.errors.colour_not_approved', [
            'lot' => $lot->lot_no_oracle,
            'status' => $lot->clr_status_oracle ?? __('production.sheet.no_colour_status'),
            'operation' => $operation->name,
        ]));
    }
}
