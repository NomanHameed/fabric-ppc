<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Foundation\Models\Quality;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
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
 * @property numeric-string $stock_qty
 * @property numeric-string $issued_qty
 * @property numeric-string $wip_qty
 * @property numeric-string $finished_qty
 * @property numeric-string $dispatched_qty
 * @property numeric-string $loss_qty
 * @property int|null $stage_id
 * @property int|null $stage_process_id
 * @property CarbonImmutable|null $stage_overridden_at
 * @property int|null $stage_overridden_by
 */
#[UseFactory(LotFactory::class)]
class Lot extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    /** Fields owned by Oracle; the import compares and overwrites exactly these. */
    public const ORACLE_FIELDS = [
        'lot_no', 'contract_id', 'quality_id', 'gsm', 'design_code', 'design', 'colour_code', 'colour',
        'g_width', 'f_width', 'po_type', 'greige_qty',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'oracle_line_id' => 'integer', 'gsm' => 'integer', 'g_width' => 'decimal:2', 'f_width' => 'decimal:2',
        'greige_qty' => 'decimal:2', 'loss_qty' => 'decimal:2', 'closed_at' => 'datetime', 'oracle_missing_at' => 'datetime',
        'state_changed_at' => 'datetime',
        'stage_id' => 'integer', 'stage_process_id' => 'integer',
        'stage_overridden_at' => 'datetime', 'stage_overridden_by' => 'integer',
        // derived by the ledger (LedgerService is their only writer)
        'stock_qty' => 'decimal:2', 'issued_qty' => 'decimal:2', 'wip_qty' => 'decimal:2', 'finished_qty' => 'decimal:2',
        'dispatched_qty' => 'decimal:2', 'last_movement_at' => 'datetime',
    ];

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Quality, $this> */
    public function quality(): BelongsTo
    {
        return $this->belongsTo(Quality::class);
    }

    /**
     * Where the lot sits in the reporting taxonomy. Derived from `current_label` and written by
     * LedgerService in the same update, so it never disagrees with the label; null when the label resolves
     * to nothing (an unmapped status, listed on the Unmapped Statuses screen).
     *
     * The exception is a manual override: while `stage_overridden_at` is set, PPC has pinned the stage and
     * the ledger leaves these two columns alone until the override is cleared (`isStageOverridden`).
     *
     * @return BelongsTo<Stage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /** @return BelongsTo<StageProcess, $this> */
    public function stageProcess(): BelongsTo
    {
        return $this->belongsTo(StageProcess::class);
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
