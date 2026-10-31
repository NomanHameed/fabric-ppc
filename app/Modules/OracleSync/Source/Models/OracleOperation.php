<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;

/**
 * `ERP.PROCESSING_OPERATIONS` — the operation master (92 rows).
 *
 * Four columns only: `CC_CODE NUMBER(8)`, `OPERATION_CODE NUMBER(8)`, `OPERATION_NAME VARCHAR2(150)`,
 * `OPERATION_TYPE VARCHAR2(15)`. There is **no `IS_ACTIVE`** and no equivalent of one, so this model
 * declares no activity flag rather than inventing a uniform one (`ERP.COST_CENTRES` does have a flag, but
 * it is `CLOSED` and it is inverted — see CostCentre).
 *
 * ## The key is not what it looks like
 *
 * Operations are scoped per cost centre, so `OPERATION_CODE` is **not globally unique**: 92 rows carry 89
 * distinct codes. Worse, `(CC_CODE, OPERATION_CODE)` is not unique either — code 120 appears four times,
 * once under cost centre 74 as ROLLING and *three times* under cost centre 90, as BLEACH, CAUSTIC SIZING
 * and SALOMATIC BLEACH. This table has no unique key at all (verified 2026-10-31), which is why
 * `$primaryKey` below is a convenience for Eloquent and not a fact, and why OracleSourceReader keys its
 * operation master on `CC_CODE:OPERATION_CODE` and documents the residual ambiguity instead of pretending
 * a bare code resolves to one name.
 *
 * The cost centre that scopes an operation is a processing row's `CC_CODE` — where the work happened — not
 * its `TO_CC_CODE`: of 3 553 live `PROCESSING_LOTS` rows with an operation, 3 290 resolve against
 * `(CC_CODE, OPERATION_CODE)`, 131 against `(TO_CC_CODE, OPERATION_CODE)` and none against `MACHINE_CODE`.
 * The remaining 263 resolve against neither: the master does not cover every pair the transactions use, so
 * a lookup has to tolerate a miss.
 *
 * @property int $CC_CODE
 * @property int $OPERATION_CODE
 * @property string|null $OPERATION_NAME
 * @property string|null $OPERATION_TYPE
 */
class OracleOperation extends SourceModel
{
    public const TABLE = 'PROCESSING_OPERATIONS';

    public const NAME_COLUMN = 'OPERATION_NAME';

    protected string $oracleSchema = self::SCHEMA_ERP;

    protected $table = self::TABLE;

    // Not a real key — see the class docblock. Nothing finds an operation by it; reads scope by CC_CODE too.
    protected $primaryKey = 'OPERATION_CODE';
}
