<?php

namespace App\Modules\Production\Models;

use App\Modules\Foundation\Models\Operation;
use Database\Factories\Modules\Production\LotRouteStepFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One planned step of a lot's route (spec §5.3 lot_route_steps, D6). Positions are derived from the route by
 * LotRouteService; the status is derived from the ledger by LotRouteService::refreshStatuses().
 *
 * @property int $id
 * @property int $lot_id
 * @property int $seq
 * @property int $operation_id
 * @property bool $is_added
 * @property string $status
 */
#[UseFactory(LotRouteStepFactory::class)]
class LotRouteStep extends Model implements Auditable
{
    /** @use HasFactory<LotRouteStepFactory> */
    use AuditableTrait, HasFactory;

    /** @var list<string> */
    public const STATUSES = ['pending', 'partial', 'done', 'skipped'];

    protected $guarded = ['id'];

    protected $casts = ['seq' => 'integer', 'is_added' => 'boolean'];

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return BelongsTo<Operation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function isActive(): bool
    {
        return $this->status !== 'skipped';
    }
}
