<?php

namespace App\Modules\Foundation\Models;

use Database\Factories\Modules\Foundation\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

#[UseFactory(DepartmentFactory::class)]
class Department extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'sort' => 'integer'];

    public function machines(): BelongsToMany
    {
        return $this->belongsToMany(Machine::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(Operation::class);
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn () => app()->getLocale() === 'ur' && $this->name_ur ? $this->name_ur : $this->name_en);
    }
}
