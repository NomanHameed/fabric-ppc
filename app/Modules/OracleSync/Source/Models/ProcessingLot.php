<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * One completed operation on one lot (findings.md §2.3). CC_CODE → TO_CC_CODE is the movement: where the work
 * happened, and where the lot went next. Quantities are whole metres.
 *
 * @property int $PROCESSING_LOT_ID
 * @property int $LOT_NUM
 * @property int $CONTRACT_NUM
 * @property int $CC_CODE
 * @property int $TO_CC_CODE
 * @property int $QNTY_USED
 * @property int $QNTY_PRODUCED
 */
class ProcessingLot extends SourceModel
{
    protected $table = 'PROCESSING_LOTS';

    protected $primaryKey = 'PROCESSING_LOT_ID';

    protected $casts = [
        'DATED' => 'datetime', 'LOT_STARTTIME' => 'datetime', 'LOT_ENDTIME' => 'datetime',
        'GREIGE_RECEIPT_DT' => 'datetime', 'ENTRY_DATE' => 'datetime',
        'QNTY_USED' => 'integer', 'QNTY_PRODUCED' => 'integer',
    ];
}
