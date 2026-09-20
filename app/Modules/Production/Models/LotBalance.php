<?php

namespace App\Modules\Production\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Live balance per (lot, position); maintained only by LedgerService.
 *
 * @property int $id
 * @property int $lot_id
 * @property string $position
 * @property numeric-string $qty
 */
class LotBalance extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['qty' => 'decimal:2'];

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
