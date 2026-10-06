<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * Quality.
 *
 * A code master Oracle never supplied (findings.md §4.1): the transaction tables carry the bare number and
 * nothing else, so without this table `Q_CODE` cannot be resolved to anything a human reads.
 */
class OracleQuality extends SourceModel
{
    protected $table = 'QUALITY_M';

    protected $primaryKey = 'Q_CODE';
}
