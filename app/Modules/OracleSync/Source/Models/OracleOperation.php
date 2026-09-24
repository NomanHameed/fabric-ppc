<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * Operation — stage naming and reprocess classification.
 *
 * A code master Oracle never supplied (findings.md §4.1): the transaction tables carry the bare number and
 * nothing else, so without this table `OPERATION_CODE` cannot be resolved to anything a human reads.
 */
class OracleOperation extends SourceModel
{
    protected $table = 'OPERATION_M';

    protected $primaryKey = 'OPERATION_CODE';
}
