<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class MachineResource extends MasterResource
{
    public function key(): string
    {
        return 'machines';
    }

    public function model(): string
    {
        return Machine::class;
    }

    public function with(): array
    {
        return ['departments'];
    }

    public function defaultSort(): string
    {
        return 'code';
    }

    public function fields(): array
    {
        return [
            Field::make('code')->required()->span(2),
            Field::make('name')->required()->span(4),
            Field::make('departments', 'multiselect')->options(
                Department::query()->orderBy('sort')->get()->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all()
            )->span(5),
            Field::make('is_active', 'bool')->span(1),
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('machines', 'code')->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:100'],
            'departments' => ['array', 'min:1'],
            'departments.*' => ['integer', 'exists:departments,id'],
            'is_active' => ['boolean'],
        ];
    }

    public function toRow(Model $m): array
    {
        return parent::toRow($m) + [
            'departments' => $m->departments->pluck('id')->all(),
            'departments_label' => $m->departments->pluck('name')->implode(', '),
        ];
    }

    public function afterSave(Model $m, array $data): void
    {
        $m->departments()->sync($data['departments'] ?? []);
    }
}
