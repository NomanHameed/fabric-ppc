<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * `ERP.PARTIES` — the party master (612 rows).
 *
 * One of the code masters findings.md §4.1 listed as missing: the transaction tables carry the bare
 * `PARTY_CODE` and nothing else. It is not missing — it is in the shared `ERP` schema rather than
 * `PRODUCTION`, which is why nothing resolved under a single schema prefix (verified 2026-10-31).
 *
 * `PARTY_CODE` and `PARTY_NAME` are both present, as expected; the other 33 columns are customer-master
 * detail (addresses, NTN/STRN, contact, account codes) that this application has no use for.
 *
 * @property int $PARTY_CODE
 * @property string $PARTY_NAME
 */
class OracleParty extends SourceModel
{
    public const TABLE = 'PARTIES';

    public const NAME_COLUMN = 'PARTY_NAME';

    protected string $oracleSchema = self::SCHEMA_ERP;

    protected $table = self::TABLE;

    // Oracle declares no key; PARTY_CODE is nullable there but is in fact distinct across all 612 rows.
    protected $primaryKey = 'PARTY_CODE';

    protected $casts = ['ENTRY_DATE' => 'datetime'];
}
