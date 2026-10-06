<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * Contract/PO detail line — the lot master. STAND-IN: the real DDL was never supplied (findings.md §4.3);
 * the columns here are inferred from the Excel export the existing importer consumes, and must be replaced
 * with the real definition before anything is trusted against a live instance.
 *
 * @property int $PC_DTL_ID
 * @property int $CONTRACT_NUM
 * @property int $LOT_NUM
 * @property int $QNTY
 */
class ProcessingContractLine extends SourceModel
{
    protected $table = 'PROCESSING_CONTRACT_D';

    protected $primaryKey = 'PC_DTL_ID';

    protected $casts = ['DATED' => 'datetime', 'QNTY' => 'integer', 'QNTY_CONT' => 'integer'];
}
