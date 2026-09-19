<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Support\PartyName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Party extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'oracle_code' => 'integer'];

    protected static function booted(): void
    {
        static::saving(fn (Party $p) => $p->name_normalized = PartyName::normalize($p->name));
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(PartyAlias::class);
    }

    public static function findByName(string $name): ?self
    {
        $n = PartyName::normalize($name);

        return static::query()->where('name_normalized', $n)->first()
            ?? PartyAlias::query()->where('alias_normalized', $n)->first()?->party;
    }
}
