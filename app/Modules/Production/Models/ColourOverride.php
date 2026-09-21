<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A logged decision to run a gated operation on a lot whose colour is not approved (spec §5.4).
 *
 * @property int $id
 * @property int $lot_id
 * @property int|null $production_line_id
 * @property string $reason
 * @property int $approved_by
 * @property CarbonImmutable $approved_at
 */
class ColourOverride extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $casts = ['lot_id' => 'integer', 'production_line_id' => 'integer', 'approved_by' => 'integer', 'approved_at' => 'datetime'];

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return BelongsTo<ProductionLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductionLine::class, 'production_line_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
