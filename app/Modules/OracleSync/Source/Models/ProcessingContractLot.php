<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `PRODUCTION.PC_DTL_LOTS` — the lot breakdown under one contract detail line, and the only place `GSM`
 * is recorded (verified on 2026-10-31; 37 490 rows). Neither `PROCESSING_CONTRACT_D` nor
 * `PROCESSING_CONTRACT_M` has a GSM column, although the flattened Excel export showed one.
 *
 * It is also **the lot↔contract-line relation**, which `PROCESSING_CONTRACT_D.LOT_NUM` only looks like: for
 * all 3 557 processing rows that point at a contract line, the `(PC_DTL_ID, LOT_NUM)` pair is here, while
 * that column is null on 3 453 of them. Anything resolving a lot to its contract line goes through here.
 *
 * **It fans out.** One `PC_DTL_ID` has up to 9 rows here, one `(PC_DTL_ID, LOT_NUM)` pair up to 5, and one
 * `PC_DTL_ID` can carry up to 5 *different* GSM values. Joining it to pick up GSM would therefore multiply
 * contract lines and their quantities — precisely the defect findings.md §5.1 found in
 * `processing_lots_trans` — so OracleSourceReader reaches it through a scalar subselect instead of a join.
 *
 * @property int $PC_MAIN_ID
 * @property int $PC_DTL_ID
 * @property int $PCL_DTL_ID
 * @property int $LOT_NUM
 * @property int|null $GSM
 */
class ProcessingContractLot extends SourceModel
{
    public const TABLE = 'PC_DTL_LOTS';

    protected string $oracleSchema = self::SCHEMA_PRODUCTION;

    protected $table = self::TABLE;

    protected $primaryKey = 'PCL_DTL_ID';

    protected $casts = ['QNTY' => 'integer', 'GSM' => 'integer'];

    /** @return BelongsTo<ProcessingContractLine, $this> */
    public function contractLine(): BelongsTo
    {
        return $this->belongsTo(ProcessingContractLine::class, 'PC_DTL_ID', 'PC_DTL_ID');
    }
}
