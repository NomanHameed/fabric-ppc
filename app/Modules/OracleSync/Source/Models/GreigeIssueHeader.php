<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `PRODUCTION.GREIGE_ISSUE_M` — greige issue document header (findings.md §2.1). FINAL is the document's
 * status, not the lot's.
 *
 * Verified against the live instance on 2026-10-31: all 13 columns match the supplied DDL exactly. Nothing
 * about this object needed correcting.
 *
 * @property int $GI_MAIN_ID
 * @property int $CC_CODE
 */
class GreigeIssueHeader extends SourceModel
{
    public const TABLE = 'GREIGE_ISSUE_M';

    protected string $oracleSchema = self::SCHEMA_PRODUCTION;

    protected $table = self::TABLE;

    protected $primaryKey = 'GI_MAIN_ID';

    protected $casts = ['DATED' => 'datetime', 'FINAL_DATE' => 'datetime', 'ENTRY_DATE' => 'datetime'];

    /** @return HasMany<GreigeIssueLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GreigeIssueLine::class, 'GI_MAIN_ID', 'GI_MAIN_ID');
    }
}
