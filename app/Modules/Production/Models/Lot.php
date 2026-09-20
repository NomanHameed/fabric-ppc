<?php

namespace App\Modules\Production\Models;

use App\Modules\Foundation\Models\Quality;
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

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('state', 'open');
    }
}
