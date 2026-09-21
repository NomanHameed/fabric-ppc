<?php

namespace App\Modules\Production\Models;

use App\Modules\Foundation\Models\Operation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One line of a production sheet (spec §5.3 production_lines). The line is the `source` of the ledger rows it
 * produced; ProductionSheetService never edits those rows — an edited or removed line is reversed and re-posted.
 *
 * @property int $id
 * @property int $production_sheet_id
 * @property int $line_no
 * @property string|null $batch_no
 * @property int $lot_id
 * @property int|null $lot_route_step_id
 * @property int $operation_id
 * @property bool $is_reprocess
 * @property string|null $reprocess_reason
 * @property numeric-string $qty_in
 * @property numeric-string $qty_out
 * @property string|null $output_type
 * @property numeric-string|null $g_width
 * @property numeric-string|null $f_width
 * @property string|null $remarks
 */
class ProductionLine extends Model implements Auditable
{
    use AuditableTrait;

    /** @var list<string> */
    public const OUTPUT_TYPES = ['dyed', 'print'];

    protected $guarded = ['id'];

    protected $casts = [
        'production_sheet_id' => 'integer', 'line_no' => 'integer', 'lot_id' => 'integer', 'lot_route_step_id' => 'integer', 'operation_id' => 'integer',
        'is_reprocess' => 'boolean', 'qty_in' => 'decimal:2', 'qty_out' => 'decimal:2', 'g_width' => 'decimal:2', 'f_width' => 'decimal:2',
    ];

    /** @return BelongsTo<ProductionSheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(ProductionSheet::class, 'production_sheet_id');
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return BelongsTo<LotRouteStep, $this> */
    public function routeStep(): BelongsTo
    {
        return $this->belongsTo(LotRouteStep::class, 'lot_route_step_id');
    }

    /** @return BelongsTo<Operation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /** @return HasOne<ColourOverride, $this> */
    public function colourOverride(): HasOne
    {
        return $this->hasOne(ColourOverride::class);
    }

    /**
     * The ledger rows this line produced (the process/reprocess movement, any override adjustment, and their reversals).
     *
     * @return MorphMany<LotMovement, $this>
     */
    public function movements(): MorphMany
    {
        return $this->morphMany(LotMovement::class, 'source')->orderBy('id');
    }
}
