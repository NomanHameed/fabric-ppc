<?php

namespace App\Modules\Production\Exceptions;

use App\Modules\Production\Models\Lot;
use RuntimeException;

/** A lot's balances disagree with the sum of its movements (spec §5.3 "Invariant"). */
final class LedgerInvariantViolation extends RuntimeException
{
    /** @param array<string, array{balance: float, expected: float}> $differences position => figures */
    public function __construct(public readonly Lot $lot, public readonly array $differences)
    {
        parent::__construct(__('production.errors.invariant', [
            'lot' => $lot->lot_no_oracle,
            'positions' => implode(', ', array_keys($differences)),
        ]));
    }
}
