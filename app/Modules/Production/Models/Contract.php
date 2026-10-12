<?php

namespace App\Modules\Production\Models;

use App\Modules\Foundation\Casts\DateOnly;
use App\Modules\Foundation\Models\Party;
use Database\Factories\Modules\Production\ContractFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[UseFactory(ContractFactory::class)]
class Contract extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['po_no' => 'integer', 'po_date' => DateOnly::class, 'contract_qty' => 'decimal:2', 'oracle_missing_at' => 'datetime'];

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return HasMany<Lot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }
}
