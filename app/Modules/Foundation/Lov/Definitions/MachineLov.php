<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\Machine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MachineLov implements LovDefinition
{
    public function name(): string
    {
        return 'machines';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function columns(): array
    {
        return [['key' => 'code', 'label' => __('masters.fields.code')], ['key' => 'label', 'label' => __('masters.fields.name')]];
    }

    public function query(array $filters): Builder
    {
        return Machine::query()->where('is_active', true)
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->whereHas('departments', fn ($d) => $d->whereKey($id)));
    }

    public function searchColumns(): array
    {
        return ['code', 'name'];
    }

    public function exactColumn(): string
    {
        return 'code';
    }

    public function row(Model $m): array
    {
        return ['id' => $m->id, 'code' => $m->code, 'label' => $m->name];
    }
}
