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
 * One bucket of the reporting taxonomy (Greige, Preparation, Dyeing, … Closed / Cancelled) — and, since
 * resolution became stage-only, the status mapping itself. Master data: created and ordered on the Stages
 * master screen, never by the application.
 *
 * `status_aliases` holds every raw status (`lots.status_oracle`) that means this stage. An alias may contain
 * `*` as a wildcard, which is how the dispatch family (`DISP 11-09-26/`) and "anything containing CANCEL"
 * are expressed without a row per date, and the sentinel `#BLANK` claims a lot that carries no status at
 * all (everything still in greige stock). StatusStageResolver reads this column; nothing in PHP hard-codes
 * a status string.
 *
 * @property array<int, mixed>|null $status_aliases whatever the JSON column holds; aliasList() cleans it
 */
#[UseFactory(StageFactory::class)]
class Stage extends Model implements Auditable
{
    /** @use HasFactory<StageFactory> */
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    /** The alias that claims a lot with no status at all, rather than leaving it out of the report. */
    public const BLANK_ALIAS = '#BLANK';

    protected $casts = ['sort_order' => 'integer', 'is_active' => 'boolean', 'status_aliases' => 'array'];

    /**
     * The aliases as a clean list of non-empty strings, whatever the column happens to hold (null, a loosely
     * typed JSON array from an older write, or the array the cast gives).
     *
     * @return list<string>
     */
    public function aliasList(): array
    {
        $aliases = $this->status_aliases;
        if (! is_array($aliases)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($alias): string => is_scalar($alias) ? trim((string) $alias) : '', $aliases),
            fn (string $alias): bool => $alias !== '',
        ));
    }

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
