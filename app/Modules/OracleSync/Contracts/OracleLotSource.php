<?php

namespace App\Modules\OracleSync\Contracts;

use App\Modules\OracleSync\Data\OracleLotRow;

interface OracleLotSource
{
    /** @return iterable<int, OracleLotRow> keyed by 1-based source row number */
    public function rows(): iterable;

    /** Human label for import history (file name, or "Oracle API"). */
    public function label(): string;
}
