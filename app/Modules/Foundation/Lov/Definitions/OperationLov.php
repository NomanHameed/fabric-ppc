<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\Operation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OperationLov implements LovDefinition
{
    public function name(): string
    {
        return 'operations';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function columns(): array
    {
        return [
            ['key' => 'label', 'label' => __('masters.fields.name')],
            ['key' => 'department', 'label' => __('masters.fields.department')],
            ['key' => 'kind', 'label' => __('masters.fields.kind')],
        ];
    }

    public function query(array $filters): Builder
    {
        return Operation::query()->with('department')->where('is_active', true)
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->when($filters['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k));
    }

    public function searchColumns(): array
    {
        return ['name'];
    }

    public function exactColumn(): string
    {
        return 'name';
    }

    public function row(Model $m): array
    {
        return ['id' => $m->id, 'code' => $m->name, 'label' => $m->name, 'department' => $m->department->name, 'kind' => $m->kind,
            'department_id' => $m->department_id, 'stage_label' => $m->stage_label];
    }
}
