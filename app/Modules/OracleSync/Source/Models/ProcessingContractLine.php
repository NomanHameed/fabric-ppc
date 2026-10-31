<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `PRODUCTION.PROCESSING_CONTRACT_D` — contract/PO detail line, the lot master.
 *
 * No longer a stand-in: the real 31-column definition was read from the live instance on 2026-10-31 and
 * three of the columns this model used to declare are not on it —
 *   - `CONTRACT_NUM`, `DATED`, `PARTY_CODE`, `REF_NUM` are on the header (ProcessingContractHeader),
 *   - `QNTY_CONT` does not exist; the line quantity is plain `QNTY`,
 *   - `GSM` does not exist here either — it is on `PC_DTL_LOTS` (ProcessingContractLot).
 *
 * The old column list was inferred from the Excel export the file importer consumes, which had already
 * joined header, line and lot into one flat report row (findings.md §4.3, now marked superseded).
 *
 * **`LOT_NUM` here is not the lot link.** It exists, but it is populated on only 8 212 of 45 561 lines, and
 * of the 3 557 processing rows that point at a contract line it agrees with the lot on 89 and is null on
 * 3 453. The lot↔line relation lives in `PC_DTL_LOTS` (ProcessingContractLot), which has the pair for all
 * 3 557 — so read lots through lots(), never by filtering this column.
 *
 * @property int $PC_MAIN_ID
 * @property int $PC_DTL_ID
 * @property int|null $LOT_NUM
 * @property int $QNTY
 */
class ProcessingContractLine extends SourceModel
{
    public const TABLE = 'PROCESSING_CONTRACT_D';

    protected string $oracleSchema = self::SCHEMA_PRODUCTION;

    protected $table = self::TABLE;

    protected $primaryKey = 'PC_DTL_ID';

    protected $casts = [
        'SHADE_REF_DT' => 'datetime', 'DIPS_DATE' => 'datetime', 'APPROVED_DT' => 'datetime',
        'CLOSED_DATE' => 'datetime', 'QNTY' => 'integer',
    ];

    /** @return BelongsTo<ProcessingContractHeader, $this> */
    public function header(): BelongsTo
    {
        return $this->belongsTo(ProcessingContractHeader::class, 'PC_MAIN_ID', 'PC_MAIN_ID');
    }

    /** @return HasMany<ProcessingContractLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(ProcessingContractLot::class, 'PC_DTL_ID', 'PC_DTL_ID');
    }
}
