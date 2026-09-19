<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Operation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class OperationResource extends MasterResource
{
    public function key(): string { return 'operations'; }

    public function model(): string { return Operation::class; }

    public function with(): array { return ['department']; }

    public function defaultSort(): string { return 'department_id'; }

    public function fields(): array
    {
        return [
            Field::make('department_id', 'select')->label('masters.fields.department')->required()->options(
                Department::query()->orderBy('sort')->get()->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all()
            )->queryType('number')->span(3),
            Field::make('name')->required()->span(4),
            Field::make('kind', 'select')->required()->options([
                ['value' => 'normal', 'label' => __('masters.options.normal')],
                ['value' => 'reprocess', 'label' => __('masters.options.reprocess')],
            ])->span(2),
            Field::make('stage_label')->required()->span(2),
            Field::make('is_active', 'bool')->span(1),
            Field::make('counts_as_finish_production', 'bool')->span(3),
            Field::make('finish_type', 'select')->options([
                ['value' => null, 'label' => '—'],
                ['value' => 'dyed', 'label' => __('masters.options.dyed')],
                ['value' => 'print', 'label' => __('masters.options.print')],
            ])->span(2),
            Field::make('is_stretch', 'bool')->span(2),
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:120',
                Rule::unique('operations', 'name')->where('department_id', request('department_id'))->ignore($record?->getKey())],
            'kind' => ['required', Rule::in(['normal', 'reprocess'])],
            'stage_label' => ['required', 'string', 'max:40'],
            'counts_as_finish_production' => ['boolean'],
            'finish_type' => ['nullable', Rule::in(['dyed', 'print'])],
            'is_stretch' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
