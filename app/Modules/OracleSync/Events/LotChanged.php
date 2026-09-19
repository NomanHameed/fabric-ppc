<?php

namespace App\Modules\OracleSync\Events;

use App\Modules\Production\Models\Lot;
use Illuminate\Foundation\Events\Dispatchable;

final class LotChanged
{
    use Dispatchable;

    /** @param array<string, array{0: mixed, 1: mixed}> $diff */
    public function __construct(public readonly Lot $lot, public readonly array $diff) {}
}
