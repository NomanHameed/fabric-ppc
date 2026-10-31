<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * `ERP.COST_CENTRES` — the cost centre master (90 rows), **and the machine master**.
 *
 * ## Why there is no OracleMachine
 *
 * findings.md §4.1 asked for a machine master to resolve `PROCESSING_LOTS.MACHINE_CODE`. There isn't one,
 * and there does not need to be: machines *are* cost centres. All 13 distinct `MACHINE_CODE` values in
 * `PROCESSING_LOTS` resolve against `ERP.COST_CENTRES.CC_CODE` (both `NUMBER(8)`), and every one of them is
 * a row with `TYPE = 'MACHINE'` — SINGING, KIER, JUMBO JIGGER, MERCERIZING, DYE PAD, DRYING, JIGGER,
 * STENTER, SANFO, DRYING (2), STENTER (2), STITCHING, DIGITAL PRINTING. `ERP.CC_SECTIONS` is a two-column
 * CC↔section link table and `ERP.SECTIONS.SECTION_CODE` is a `VARCHAR2(4)`, so neither of those is it
 * either. (All verified against the live instance on 2026-10-31.)
 *
 * The earlier `OracleMachine` model over a `MACHINE_M` table has therefore been **removed** rather than
 * re-pointed at `ERP.COST_CENTRES`. Two SourceModels over one table buys nothing — `MACHINE_CODE` and
 * `CC_CODE` are two foreign keys into the same master, which relations express perfectly well
 * (ProcessingLot::machine() / ::costCentre()) — while it does cost something: a model named after an object
 * that does not exist, and a silent key collision in `PingOracleSource::sourceTables()`, which is keyed by
 * table name and would quietly ping only one of the two classes.
 *
 * ## Columns that changed
 *
 * `CC_NAME` → **`COST_CENTRE VARCHAR2(100) NOT NULL`**; there is no `CC_SHORT`; and there is no `IS_ACTIVE`
 * — the flag is **`CLOSED VARCHAR2(1)`, whose sense is inverted**. Live values are `NULL` (50 rows) and
 * `'N'` (40): nothing is closed today, and an absent value means open, which is why open() tests for
 * "not 'Y'" rather than for 'N'.
 *
 * @property int $CC_CODE
 * @property string $COST_CENTRE
 * @property string|null $TYPE
 * @property string|null $CLOSED
 */
class CostCentre extends SourceModel
{
    public const TABLE = 'COST_CENTRES';

    /** Was `CC_NAME` in the inferred shape. */
    public const NAME_COLUMN = 'COST_CENTRE';

    /** The `TYPE` of a cost centre that is a machine. */
    public const TYPE_MACHINE = 'MACHINE';

    protected string $oracleSchema = self::SCHEMA_ERP;

    protected $table = self::TABLE;

    // Oracle declares no key; CC_CODE is nullable there but is distinct across all 90 rows.
    protected $primaryKey = 'CC_CODE';

    protected $casts = ['CREATION_DATE' => 'datetime'];

    /** Closed only when CLOSED is explicitly 'Y'. NULL and 'N' both mean open. */
    public function isClosed(): bool
    {
        return is_string($this->CLOSED) && strtoupper($this->CLOSED) === 'Y';
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('CLOSED')->orWhereRaw("upper(CLOSED) <> 'Y'"));
    }
}
