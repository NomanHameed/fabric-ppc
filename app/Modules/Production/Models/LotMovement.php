<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Production\Ledger\Position;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Insert-only ledger row, written only by LedgerService. Corrections are new rows with reversal_of_id;
 * nothing here is ever updated or deleted (the model refuses both).
 *
 * @property int $id
 * @property int $lot_id
 * @property string $kind
 * @property string|null $from_position
 * @property string|null $to_position
 * @property numeric-string $qty_out_of_from
 * @property numeric-string $qty_into_to
 * @property string|null $source_type
 * @property int|null $source_id
 * @property CarbonImmutable $moved_at
 * @property int|null $user_id
 * @property int|null $reversal_of_id
 * @property string|null $reason
 */
class LotMovement extends Model
{
    /** @var list<string> */
    public const KINDS = ['import', 'issue', 'process', 'reprocess', 'dispatch', 'loss', 'adjustment'];

    protected $guarded = ['id'];

    protected $casts = ['qty_out_of_from' => 'decimal:2', 'qty_into_to' => 'decimal:2', 'moved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lot movements are insert-only; post a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Lot movements are insert-only; post a reversal instead.'));
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<self, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function fromPosition(): ?Position
    {
        return $this->from_position === null ? null : Position::from($this->from_position);
    }

    public function toPosition(): ?Position
    {
        return $this->to_position === null ? null : Position::from($this->to_position);
    }
}
