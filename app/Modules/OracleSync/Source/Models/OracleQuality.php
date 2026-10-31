<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * `ERP.QUALITIES` — the fabric quality master (3 035 rows).
 *
 * The name column is **`DESCRIPTION VARCHAR2(50)`**, not `QUALITY_NAME` (verified 2026-10-31). The table
 * also carries the construction itself — `REED`, `PICK`, `WARP`, `WEFT`, `LOOM_TYPE`, `BLEND`,
 * `BLEND_RATIO1/2`, `FAB_TYPE`, `WARP_TYPE`, `WEFT_TYPE`, `APPROVED` — which is how the mill's quality
 * strings ("88X60/30X30 100% VISCOSE") are composed, so a future screen can show the parts rather than
 * parse the description.
 *
 * `DESCRIPTION` is **often absent**: 672 of the 3 035 rows have none, and of the 149 `Q_CODE`s the
 * processing rows actually use only 117 resolve to one. The construction columns are the fallback; a
 * consumer must not assume every quality has a name.
 *
 * @property int $Q_CODE
 * @property string|null $DESCRIPTION
 */
class OracleQuality extends SourceModel
{
    public const TABLE = 'QUALITIES';

    /** Was `QUALITY_NAME` in the inferred shape; Oracle calls it DESCRIPTION. */
    public const NAME_COLUMN = 'DESCRIPTION';

    protected string $oracleSchema = self::SCHEMA_ERP;

    protected $table = self::TABLE;

    // Oracle declares no key; Q_CODE is nullable there but is distinct across all 3 035 rows.
    protected $primaryKey = 'Q_CODE';
}
