<?php

namespace App\Modules\Foundation\Models;

use Database\Factories\Modules\Foundation\MachineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[UseFactory(MachineFactory::class)]
class Machine extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class);
    }
}
