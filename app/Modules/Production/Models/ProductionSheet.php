<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Shift;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Production\ProductionSheetFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One paper production sheet: date + shift + machine (unique), with its lines (spec §4.4 screen 5, §5.3 production_sheets).
 *
 * @property int $id
 * @property CarbonImmutable $sheet_date
 * @property int $shift_id
 * @property int $department_id
 * @property int $machine_id
 * @property int $downtime_minutes
 * @property string|null $remarks
 * @property int $entered_by
 */
#[UseFactory(ProductionSheetFactory::class)]
class ProductionSheet extends Model implements Auditable
{
    /** @use HasFactory<ProductionSheetFactory> */
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'sheet_date' => 'date', 'shift_id' => 'integer', 'department_id' => 'integer', 'machine_id' => 'integer',
        'downtime_minutes' => 'integer', 'entered_by' => 'integer',
    ];

    /** @return HasMany<ProductionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductionLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
