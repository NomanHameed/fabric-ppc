<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class DepartmentResource extends MasterResource
{
    public function key(): string
    {
        return 'departments';
    }

    public function model(): string
    {
        return Department::class;
    }

    public function defaultSort(): string
    {
        return 'sort';
    }

    public function fields(): array
    {
        return [
            Field::make('code')->required()->span(2),
            Field::make('name_en')->required()->span(4),
            Field::make('name_ur')->span(4),
            Field::make('sort', 'number')->span(1),
            Field::make('is_active', 'bool')->span(1),
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('departments', 'code')->ignore($record?->getKey())],
            'name_en' => ['required', 'string', 'max:100'],
            'name_ur' => ['nullable', 'string', 'max:100'],
            'sort' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
