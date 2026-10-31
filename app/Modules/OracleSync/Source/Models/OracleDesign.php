<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * `ERP.DESIGNS` — design master (20 467 rows). `DESIGN_CODE NUMBER(6) NOT NULL`, `DESIGN_NAME
 * VARCHAR2(50)`: exactly the two columns expected, in the `ERP` schema (verified 2026-10-31).
 *
 * @property int $DESIGN_CODE
 * @property string|null $DESIGN_NAME
 */
class OracleDesign extends SourceModel
{
    public const TABLE = 'DESIGNS';

    public const NAME_COLUMN = 'DESIGN_NAME';

    protected string $oracleSchema = self::SCHEMA_ERP;

    protected $table = self::TABLE;

    protected $primaryKey = 'DESIGN_CODE';
}
