<?php

namespace App\Modules\Foundation\Models;

use Database\Factories\Modules\Foundation\MachineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property string $code
 * @property string $name
 * @property numeric-string|null $hourly_capacity
 * @property numeric-string|null $daily_capacity
 * @property string $capacity_uom
 * @property bool $is_active
 */
#[UseFactory(MachineFactory::class)]
class Machine extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    /** Mill day: two twelve-hour shifts (FoundationSeeder seeds A 08:00-20:00 and B 20:00-08:00). */
    public const HOURS_PER_DAY = 24;

    /** The only unit any capacity figure is expressed in today; the column exists so it can never be guessed. */
    public const UOM_METRES = 'metres';

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'hourly_capacity' => 'decimal:2', 'daily_capacity' => 'decimal:2'];

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class);
    }

    // No allocations() relation on purpose: machine_allocations belongs to Production, and Foundation must
    // not depend on Production. MachineAllocation::machine() is the belongsTo side; the capacity service
    // queries allocations directly.

    /**
     * Metres this machine can do in a day: the daily figure when it is set, otherwise the hourly figure over a
     * 24-hour mill day. Null when neither has been entered — utilisation is then unknown, not zero.
     */
    public function dailyCapacityQty(): ?float
    {
        if ($this->daily_capacity !== null) {
            return (float) $this->daily_capacity;
        }

        return $this->hourly_capacity === null ? null : (float) $this->hourly_capacity * self::HOURS_PER_DAY;
    }
}
