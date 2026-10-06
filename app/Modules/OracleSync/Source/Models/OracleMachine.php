<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * Machine — identity, output and downtime.
 *
 * A code master Oracle never supplied (findings.md §4.1): the transaction tables carry the bare number and
 * nothing else, so without this table `MACHINE_CODE` cannot be resolved to anything a human reads.
 */
class OracleMachine extends SourceModel
{
    protected $table = 'MACHINE_M';

    protected $primaryKey = 'MACHINE_CODE';
}
