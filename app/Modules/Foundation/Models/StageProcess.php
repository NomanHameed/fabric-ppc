<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Production\Models\Lot;
use Database\Factories\Modules\Foundation\StageProcessFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One position inside a stage (Bleaching, Dyeing, Sanforizing / Calendaring, Dispatch, Cancelled, …).
 *
 * `status_aliases` is the raw-status side of the mapping: every `lots.current_label` that means this process.
 * An alias may contain `*` as a wildcard, which is how the dispatch family (`DISP 11-09-26/`) and "anything
 * containing CANCEL" are expressed without a row per date. StatusStageResolver reads this column; nothing in
 * PHP hard-codes a status string.
 *
 * @property array<int, mixed>|null $status_aliases whatever the JSON column holds; aliasList() cleans it
 */
#[UseFactory(StageProcessFactory::class)]
class StageProcess extends Model implements Auditable
{
    /** @use HasFactory<StageProcessFactory> */
    use AuditableTrait, HasFactory;

    /**
     * Which of the report's quantity columns a process carries; null for a state that holds no metres.
     *
     * @var list<string>
     */
    public const QUANTITY_TYPES = ['stock', 'wip', 'finished', 'dispatched', 'loss'];

    protected $guarded = ['id'];

    protected $casts = [
        'stage_id' => 'integer',
        'sort_order' => 'integer',
        'status_aliases' => 'array',
        'is_wip' => 'boolean',
        'is_finished' => 'boolean',
        'is_dispatch' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Stage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /** @return HasMany<Lot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class);
    }

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
}
