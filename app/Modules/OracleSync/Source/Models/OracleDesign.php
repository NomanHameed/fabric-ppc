<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * Design — grouping and reporting.
 *
 * A code master Oracle never supplied (findings.md §4.1): the transaction tables carry the bare number and
 * nothing else, so without this table `DESIGN_CODE` cannot be resolved to anything a human reads.
 */
class OracleDesign extends SourceModel
{
    protected $table = 'DESIGN_M';

    protected $primaryKey = 'DESIGN_CODE';
}
