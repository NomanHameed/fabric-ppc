<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Support\PartyName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class PartyAlias extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(fn (PartyAlias $a) => $a->alias_normalized = PartyName::normalize($a->alias));
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
