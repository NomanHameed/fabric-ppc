<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\LookupValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class LookupLov implements LovDefinition
{
    public function name(): string
    {
        return 'lookup';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function columns(): array
    {
        return [['key' => 'code', 'label' => __('masters.fields.code')], ['key' => 'label', 'label' => __('masters.fields.label')]];
    }

    public function query(array $filters): Builder
    {
        $key = $filters['list_key'] ?? throw ValidationException::withMessages(['filters.list_key' => __('forms.lov_list_key_required')]);

        return LookupValue::query()->where('list_key', $key)->where('is_active', true);
    }

    public function searchColumns(): array
    {
        return ['code', 'label_en', 'label_ur'];
    }

    public function exactColumn(): string
    {
        return 'code';
    }

    public function row(Model $m): array
    {
        return ['id' => $m->id, 'code' => $m->code, 'label' => $m->label];
    }
}
