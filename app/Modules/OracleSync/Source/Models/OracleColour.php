<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * `ERP.COLOURS` — colour/shade master (4 813 rows). `COLOUR_CODE NUMBER(4) NOT NULL`, `COLOUR_NAME
 * VARCHAR2(50)`: exactly the two columns expected, in the `ERP` schema (verified 2026-10-31).
 *
 * @property int $COLOUR_CODE
 * @property string|null $COLOUR_NAME
 */
class OracleColour extends SourceModel
{
    public const TABLE = 'COLOURS';

    public const NAME_COLUMN = 'COLOUR_NAME';

    protected string $oracleSchema = self::SCHEMA_ERP;

    protected $table = self::TABLE;

    protected $primaryKey = 'COLOUR_CODE';
}
