<?php

namespace App\Modules\Activity\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One meaningful business action: who did what to which record, from where.
 *
 * Insert-only. This is a log, not state — a wrong entry is corrected by recording the correction, never by
 * editing history. It complements `audits` (which records column-level model diffs): this table records the
 * *intent* ("approved the colour", "moved the greige to printing") in words a mill supervisor reads.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $user_name
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $description
 * @property array<string, mixed>|null $context
 * @property string|null $ip_address
 * @property int|null $user_device_id
 * @property string|null $device_id
 * @property string|null $computer_name
 * @property string|null $user_agent
 * @property CarbonImmutable $occurred_at
 */
class UserAction extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['context' => 'array', 'occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('User actions are insert-only; record a new action instead.'));
        static::deleting(fn () => throw new LogicException('User actions are insert-only; they are never deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<UserDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'user_device_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<$this>  $q
     * @return Builder<$this>
     */
    public function scopeForSubject(Builder $q, Model $subject): Builder
    {
        return $q->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey());
    }

    /**
     * The description re-rendered in the current locale. `description` is stored once in the fallback locale so
     * the log reads the same for everyone after the fact; this is for showing it to a user in their language.
     */
    public function label(): string
    {
        $key = 'activity.actions.'.$this->action;
        $translated = __($key, $this->context ?? []);

        return is_string($translated) && $translated !== $key ? $translated : $this->description;
    }
}
