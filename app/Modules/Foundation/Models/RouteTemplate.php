<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class RouteTemplate extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'priority' => 'integer'];

    public function steps(): HasMany
    {
        return $this->hasMany(RouteTemplateStep::class)->orderBy('seq');
    }
}
