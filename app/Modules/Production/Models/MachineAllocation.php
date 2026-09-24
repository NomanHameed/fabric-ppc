<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Shift;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Production\MachineAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Metres of a machine's capacity committed to one operation on one date — "machine daily capacity 1000,
 * allocated 600 to this process today".
 *
 * This is plan, not production. It never touches lot_movements or lot_balances; what actually ran is the
 * ledger's business and LedgerService remains its only writer. Utilisation compares the two worlds by reading
 * this table against the machine's capacity.
 *
 * @property int $id
 * @property int $machine_id
 * @property int|null $operation_id
 * @property int $department_id
 * @property int|null $shift_id
 * @property int|null $lot_id
 * @property CarbonImmutable $allocated_on
 * @property numeric-string $allocated_qty
 * @property string $uom
 * @property string|null $note
 * @property int|null $created_by
 */
#[UseFactory(MachineAllocationFactory::class)]
class MachineAllocation extends Model implements Auditable
{
    /** @use HasFactory<MachineAllocationFactory> */
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'machine_id' => 'integer', 'operation_id' => 'integer', 'department_id' => 'integer',
        'shift_id' => 'integer', 'lot_id' => 'integer', 'created_by' => 'integer',
        'allocated_on' => 'date', 'allocated_qty' => 'decimal:2',
    ];

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return BelongsTo<Operation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Inclusive date range.
     *
     * The bounds are asymmetric on purpose. `allocated_on` is a DATE on MySQL but plain text on SQLite, where
     * a `date` cast writes 'Y-m-d H:i:s' and a raw seeder insert writes 'Y-m-d'; a bare BETWEEN on two date
     * strings then drops every row on the last day. A date-only lower bound and an end-of-day upper bound
     * catch both spellings on both drivers, and still use the index.
     *
     * @param  Builder<$this>  $q
     * @return Builder<$this>
     */
    public function scopeBetween(Builder $q, string $from, string $to): Builder
    {
        return $q->whereBetween('allocated_on', [$from, $to.' 23:59:59']);
    }
}
