<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `PRODUCTION.PROCESSING_LOTS` — one completed operation on one lot (findings.md §2.3).
 *
 * `CC_CODE` → `TO_CC_CODE` is the movement: where the work happened, and where the lot went next.
 * Quantities are whole metres.
 *
 * Verified against the live instance on 2026-10-31: 32 columns, and two corrections to what was inferred
 * from the supplied DDL text —
 *   - there is **no `PLOT_NUM`** (findings.md §6 q.9 is answered: the column does not exist), and
 *   - `PC_DTL_ID NUMBER(8)` **is** here, populated on 3 617 of 3 618 rows, so a processing row carries its
 *     own contract-line key and does not have to be matched back to `GREIGE_ISSUE_D` for one
 *     (OracleSourceReader::movements()).
 *
 * @property int $PROCESSING_LOT_ID
 * @property int $LOT_NUM
 * @property int|null $PC_DTL_ID
 * @property int $CONTRACT_NUM
 * @property int $CC_CODE
 * @property int $TO_CC_CODE
 * @property int $MACHINE_CODE
 * @property int|null $OPERATION_CODE
 * @property int $QNTY_USED
 * @property int $QNTY_PRODUCED
 */
class ProcessingLot extends SourceModel
{
    public const TABLE = 'PROCESSING_LOTS';

    protected string $oracleSchema = self::SCHEMA_PRODUCTION;

    protected $table = self::TABLE;

    protected $primaryKey = 'PROCESSING_LOT_ID';

    protected $casts = [
        'DATED' => 'datetime', 'LOT_STARTTIME' => 'datetime', 'LOT_ENDTIME' => 'datetime',
        'GREIGE_RECEIPT_DT' => 'datetime', 'ENTRY_DATE' => 'datetime',
        'QNTY_USED' => 'integer', 'QNTY_PRODUCED' => 'integer',
    ];

    /** @return BelongsTo<ProcessingContractLine, $this> */
    public function contractLine(): BelongsTo
    {
        return $this->belongsTo(ProcessingContractLine::class, 'PC_DTL_ID', 'PC_DTL_ID');
    }

    /** Where the work happened. Machines are cost centres too — see CostCentre. */
    /** @return BelongsTo<CostCentre, $this> */
    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'CC_CODE', 'CC_CODE');
    }

    /** @return BelongsTo<CostCentre, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(CostCentre::class, 'MACHINE_CODE', 'CC_CODE');
    }
}
