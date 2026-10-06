<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * Party.
 *
 * A code master Oracle never supplied (findings.md §4.1): the transaction tables carry the bare number and
 * nothing else, so without this table `PARTY_CODE` cannot be resolved to anything a human reads.
 */
class OracleParty extends SourceModel
{
    protected $table = 'PARTY_M';

    protected $primaryKey = 'PARTY_CODE';
}
