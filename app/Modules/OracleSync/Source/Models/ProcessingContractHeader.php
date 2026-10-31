<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `PRODUCTION.PROCESSING_CONTRACT_M` — the contract/PO **header**.
 *
 * findings.md §4.3 recorded this object as "never seen". It exists, it has 8 304 rows, and it is where
 * `CONTRACT_NUM`, `DATED`, `PARTY_CODE` and `REF_NUM` actually live (verified on 2026-10-31). The earlier
 * model flattened header and line into one `PROCESSING_CONTRACT_D`, because its columns were inferred from
 * a *report* export that had already joined them — see the superseded note in findings.md §4.3.
 *
 * `PC_MAIN_ID` is the key, not `CONTRACT_NUM`: 8 304 headers carry only 8 297 distinct contract numbers, so
 * a contract number can repeat and must never be used as an identity.
 *
 * @property int $PC_MAIN_ID
 * @property int $CONTRACT_NUM
 * @property int $PARTY_CODE
 */
class ProcessingContractHeader extends SourceModel
{
    public const TABLE = 'PROCESSING_CONTRACT_M';

    protected string $oracleSchema = self::SCHEMA_PRODUCTION;

    protected $table = self::TABLE;

    protected $primaryKey = 'PC_MAIN_ID';

    protected $casts = [
        'DATED' => 'datetime', 'APPROVED_DATE' => 'datetime', 'CHECKED_DATE' => 'datetime',
        'CLOSED_DATE' => 'datetime', 'ENTRY_DATE' => 'datetime',
    ];

    /** @return HasMany<ProcessingContractLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ProcessingContractLine::class, 'PC_MAIN_ID', 'PC_MAIN_ID');
    }
}
