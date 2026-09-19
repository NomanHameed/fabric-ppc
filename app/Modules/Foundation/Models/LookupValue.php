<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class LookupValue extends Model implements Auditable
{
    use AuditableTrait;

    public const LISTS = ['colour_status', 'po_type', 'loss_reason', 'hold_reason', 'reprocess_reason'];

    protected $guarded = ['id'];

    protected $casts = ['meta' => 'array', 'is_active' => 'boolean', 'sort' => 'integer'];

    public function scopeList(Builder $query, string $key): Builder
    {
        return $query->where('list_key', $key)->orderBy('sort');
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => app()->getLocale() === 'ur' && $this->label_ur ? $this->label_ur : $this->label_en);
    }
}
