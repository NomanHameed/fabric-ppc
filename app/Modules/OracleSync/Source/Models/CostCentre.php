<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * Cost centre — the department a movement happened at or went to.
 *
 * A code master Oracle never supplied (findings.md §4.1): the transaction tables carry the bare number and
 * nothing else, so without this table `CC_CODE` cannot be resolved to anything a human reads.
 */
class CostCentre extends SourceModel
{
    protected $table = 'COST_CENTRE_M';

    protected $primaryKey = 'CC_CODE';
}
