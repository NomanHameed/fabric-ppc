<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ShiftResource extends MasterResource
{
    public function key(): string { return 'shifts'; }

    public function model(): string { return Shift::class; }

    public function defaultSort(): string { return 'code'; }

    public function fields(): array
    {
        return [
            Field::make('code')->required()->span(2),
            Field::make('name')->required()->span(4),
            Field::make('starts_at', 'time')->required()->span(2),
            Field::make('ends_at', 'time')->required()->span(2),
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:5', Rule::unique('shifts', 'code')->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:50'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
        ];
    }
}
