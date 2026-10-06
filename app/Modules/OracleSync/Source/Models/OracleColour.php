<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * Colour / shade.
 *
 * A code master Oracle never supplied (findings.md §4.1): the transaction tables carry the bare number and
 * nothing else, so without this table `COLOUR_CODE` cannot be resolved to anything a human reads.
 */
class OracleColour extends SourceModel
{
    protected $table = 'COLOUR_M';

    protected $primaryKey = 'COLOUR_CODE';
}
