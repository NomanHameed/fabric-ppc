<?php

namespace App\Modules\OracleSync\Events;

use App\Modules\Production\Models\Lot;
use Illuminate\Foundation\Events\Dispatchable;

final class LotImported
{
    use Dispatchable;

    public function __construct(public readonly Lot $lot) {}
}
