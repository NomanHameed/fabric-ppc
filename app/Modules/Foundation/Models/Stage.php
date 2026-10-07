<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Production\Models\Lot;
use Database\Factories\Modules\Foundation\StageFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One bucket of the reporting taxonomy (Preparation, Dyeing, … Closed / Cancelled), holding the processes a
 * lot can sit at. Master data: created and ordered on the Stages master screen, never by the application.
 */
#[UseFactory(StageFactory::class)]
class Stage extends Model implements Auditable
{
    /** @use HasFactory<StageFactory> */
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['sort_order' => 'integer', 'is_active' => 'boolean'];

    /** @return HasMany<StageProcess, $this> */
    public function processes(): HasMany
    {
        return $this->hasMany(StageProcess::class)->orderBy('sort_order')->orderBy('code');
    }

    /** @return HasMany<Lot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }
}
