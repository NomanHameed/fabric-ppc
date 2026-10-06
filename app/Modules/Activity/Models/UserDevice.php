<?php

namespace App\Modules\Activity\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Activity\UserDeviceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One physical machine in the mill, as far as HTTP can tell.
 *
 * `device_id` is an opaque ULID this application issued and stored in a long-lived encrypted cookie; it is not
 * a hardware identifier and is not claimed to be one. `computer_name` is what the operator registered for the
 * machine — the equivalent of Oracle's `COMPUTER` column, and the only part a human recognises.
 *
 * @property int $id
 * @property string $device_id
 * @property string|null $computer_name
 * @property int|null $user_id
 * @property string|null $last_ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 */
#[UseFactory(UserDeviceFactory::class)]
class UserDevice extends Model implements Auditable
{
    /** @use HasFactory<UserDeviceFactory> */
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];

    /**
     * Only the computer name is a user decision; the rest is captured from the request.
     *
     * @var list<string>
     */
    protected $auditInclude = ['computer_name', 'user_id'];

    /**
     * Updates only. A device row is bookkeeping the server writes for itself, so its creation carries no
     * decision worth auditing — and auditing it doubles the write cost of first contact with a terminal.
     *
     * @var list<string>
     */
    protected $auditEvents = ['updated'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<UserAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(UserAction::class);
    }

    /** What to show a human: the registered name, or the short form of the id when nobody has named it yet. */
    public function label(): string
    {
        return $this->computer_name ?? __('activity.device.unnamed', ['id' => substr($this->device_id, -6)]);
    }
}
