<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Foundation\Models\Quality;
use App\Modules\Foundation\Models\Stage;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Production\LotFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Derived columns (LedgerService is their only writer):
 *
 * @property numeric-string $greige_in_stock_oracle
 * @property numeric-string $qty_issued
 * @property numeric-string $wip
 * @property numeric-string $finished
 * @property numeric-string $dispatch
 * @property numeric-string $loss_qty
 * @property int|null $stage_id
 * @property CarbonImmutable|null $stage_overridden_at
 * @property int|null $stage_overridden_by
 */
#[UseFactory(LotFactory::class)]
class Lot extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    /**
     * Fields owned by Oracle; the import compares and overwrites exactly these.
     *
     * The six denormalised copies (`party_oracle_id`, `party_oracle_name`, `po_no`, `ref`, `date_oracle`,
     * `quality`) are Oracle's too: they come off the same export row as `contract_id` and `quality_id`, so
     * the import must restate them or a changed PO date would show the old one on the lot while the
     * contract carried the new one.
     */
    public const ORACLE_FIELDS = [
        'lot_no_oracle', 'contract_id', 'quality_id', 'party_oracle_id', 'party_oracle_name', 'po_no', 'ref',
        'date_oracle', 'quality', 'gsm', 'design_oracle_id', 'design', 'color_oracle_id', 'color_oracle_name',
        'gw_oracle', 'f_width', 'po_type_oracle', 'greige_received_oracle',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'oracle_line_id' => 'integer', 'gsm' => 'integer', 'gw_oracle' => 'decimal:2', 'f_width' => 'decimal:2',
        'greige_received_oracle' => 'decimal:2', 'loss_qty' => 'decimal:2', 'closed_at' => 'datetime', 'oracle_missing_at' => 'datetime',
        'state_changed_at' => 'datetime',
        // The denormalised copies of the PO, its party and the quality (see ORACLE_FIELDS).
        'party_oracle_id' => 'integer', 'po_no' => 'integer', 'date_oracle' => 'datetime',
        'stage_id' => 'integer',
        'stage_overridden_at' => 'datetime', 'stage_overridden_by' => 'integer',
        // derived by the ledger (LedgerService is their only writer)
        'greige_in_stock_oracle' => 'decimal:2', 'qty_issued' => 'decimal:2', 'wip' => 'decimal:2', 'finished' => 'decimal:2',
        'dispatch' => 'decimal:2', 'last_movement_at' => 'datetime',
    ];

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * The quality master row.
     *
     * Deliberately **not** called `quality`: `lots.quality` is now a column (the denormalised description
     * the report prints), and Eloquent resolves an attribute before a relation — `$lot->quality` would hand
     * back the string and the relation would be unreachable. Anything that only wants the description reads
     * the column; this relation is for the rest of the master row (`LotRouteService` matches a route
     * template on it).
     *
     * @return BelongsTo<Quality, $this>
     */
    public function qualityRecord(): BelongsTo
    {
        return $this->belongsTo(Quality::class, 'quality_id');
    }

    /**
     * Where the lot sits in the reporting taxonomy. Derived from `status_oracle` and written by
     * LedgerService in the same update, so it never disagrees with the label; null when the label resolves
     * to nothing (an unmapped status, listed on the Unmapped Statuses screen).
     *
     * The exception is a manual override: while `stage_overridden_at` is set, PPC has pinned the stage and
     * the ledger leaves this column alone until the override is cleared (`isStageOverridden`).
     *
     * @return BelongsTo<Stage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /**
     * Who pinned the stage by hand, if anyone.
     *
     * @return BelongsTo<User, $this>
     */
    public function stageOverrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stage_overridden_by');
    }

    /** True while the stage is pinned by hand and the ledger must not re-derive it. */
    public function isStageOverridden(): bool
    {
        return $this->stage_overridden_at !== null;
    }

    /** @return HasMany<LotRouteStep, $this> */
    public function routeSteps(): HasMany
    {
        return $this->hasMany(LotRouteStep::class)->orderBy('seq');
    }

    /** @return HasMany<LotMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(LotMovement::class)->orderBy('moved_at')->orderBy('id');
    }

    /** @return HasMany<LotBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(LotBalance::class)->orderBy('id');
    }

    /** @return HasMany<ProductionLine, $this> */
    public function productionLines(): HasMany
    {
        return $this->hasMany(ProductionLine::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('state', 'open');
    }
}
