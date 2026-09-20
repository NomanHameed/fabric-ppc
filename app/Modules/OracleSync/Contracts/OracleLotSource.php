<?php

namespace App\Modules\OracleSync\Contracts;

use App\Modules\OracleSync\Data\OracleLotRow;
use App\Modules\OracleSync\Data\OracleRowError;

interface OracleLotSource
{
    /** @return iterable<int, OracleLotRow|OracleRowError> keyed by 1-based source row number */
    public function rows(): iterable;

    /** Human label for import history (file name, or "Oracle API"). */
    public function label(): string;
}
