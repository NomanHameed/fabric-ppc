<?php

namespace App\Modules\Foundation\Models;

use Carbon\CarbonImmutable;
use Database\Factories\Modules\Foundation\StageProcessFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One Oracle production line, linked to the reporting stage it belongs to.
 *
 * **Deliberately restructured by the user** (migration `2026_10_13_000100`): this is no longer the
 * process-name taxonomy. `code`, `name`, `description`, `sort_order`, `quantity_type`, the three `is_*`
 * flags, `status_aliases` and `is_active` are gone, and in their place are the twenty Oracle fields the mill
 * works from — the same columns, with the same types, as on `lots`. `QUANTITY_TYPES` and `aliasList()` went
 * with them; the status mapping lives on `Stage::$status_aliases` and resolution is stage-only.
 *
 * `stage_id` is the link to the stage and is `restrictOnDelete`, so a stage with rows here cannot be deleted
 * while they exist — the master screen reports that as `forms.in_use`.
 *
 * @property int|null $party_oracle_id
 * @property string|null $party_oracle_name
 * @property int|null $po_no
 * @property string|null $ref
 * @property string|null $design_oracle_id
 * @property string|null $color_oracle_id
 * @property string|null $color_oracle_name
 * @property string|null $clr_status_oracle
 * @property string|null $po_type_oracle
 * @property CarbonImmutable|null $date_oracle
 * @property string|null $lot_no_oracle
 * @property string|null $quality
 * @property numeric-string|null $gw_oracle
 * @property string|null $status_oracle
 * @property numeric-string|null $greige_received_oracle
 * @property numeric-string|null $greige_in_stock_oracle
 * @property numeric-string|null $qty_issued
 * @property numeric-string|null $wip
 * @property numeric-string|null $finished
 * @property numeric-string|null $dispatch
 */
#[UseFactory(StageProcessFactory::class)]
class StageProcess extends Model implements Auditable
{
    /** @use HasFactory<StageProcessFactory> */
    use AuditableTrait, HasFactory;

    /**
     * The six quantity columns, in the order the Month Wise Fabric Report prints them.
     *
     * @var list<string>
     */
    public const QUANTITY_COLUMNS = [
        'greige_received_oracle', 'greige_in_stock_oracle', 'qty_issued', 'wip', 'finished', 'dispatch',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'stage_id' => 'integer',
        'party_oracle_id' => 'integer',
        'po_no' => 'integer',
        'date_oracle' => 'datetime',
        'gw_oracle' => 'decimal:2',
        'greige_received_oracle' => 'decimal:2',
        'greige_in_stock_oracle' => 'decimal:2',
        'qty_issued' => 'decimal:2',
        'wip' => 'decimal:2',
        'finished' => 'decimal:2',
        'dispatch' => 'decimal:2',
    ];

    /** @return BelongsTo<Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }
}
