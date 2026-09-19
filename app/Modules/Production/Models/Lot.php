<?php

namespace App\Modules\Production\Models;

use App\Modules\Foundation\Models\Quality;
use Database\Factories\Modules\Production\LotFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

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
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function quality(): BelongsTo
    {
        return $this->belongsTo(Quality::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('state', 'open');
    }
}
