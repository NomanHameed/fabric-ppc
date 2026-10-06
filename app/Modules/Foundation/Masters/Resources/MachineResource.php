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
            // Capacity in metres. Either figure alone is enough: the daily one wins, otherwise the hourly one
            // is read over a 24-hour mill day (Machine::dailyCapacityQty).
            Field::make('hourly_capacity', 'number')->span(2),
            Field::make('daily_capacity', 'number')->span(2),
            Field::make('capacity_uom')->readonly()->span(2),
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
            'hourly_capacity' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'daily_capacity' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'capacity_uom' => ['nullable', Rule::in([Machine::UOM_METRES])],
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
