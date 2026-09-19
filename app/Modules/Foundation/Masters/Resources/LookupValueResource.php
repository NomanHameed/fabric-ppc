<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\LookupValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class LookupValueResource extends MasterResource
{
    public function key(): string
    {
        return 'lookups';
    }

    public function model(): string
    {
        return LookupValue::class;
    }

    public function defaultSort(): string
    {
        return 'list_key';
    }

    public function fields(): array
    {
        return [
            Field::make('list_key', 'select')->required()->options(
                array_map(fn ($k) => ['value' => $k, 'label' => __('masters.options.'.$k)], LookupValue::LISTS)
            )->span(3),
            Field::make('code')->required()->span(2),
            Field::make('label_en')->required()->span(3),
            Field::make('label_ur')->span(3),
            Field::make('sort', 'number')->span(1),
            Field::make('approved', 'bool')->queryType(null)->span(3),
            Field::make('is_active', 'bool')->span(1),
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'list_key' => ['required', Rule::in(LookupValue::LISTS)],
            'code' => ['required', 'string', 'max:40',
                Rule::unique('lookup_values', 'code')->where('list_key', request('list_key'))->ignore($record?->getKey())],
            'label_en' => ['required', 'string', 'max:120'],
            'label_ur' => ['nullable', 'string', 'max:120'],
            'sort' => ['required', 'integer', 'min:0'],
            'approved' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function toRow(Model $m): array
    {
        return array_merge(parent::toRow($m), ['approved' => (bool) ($m->meta['approved'] ?? false)]);
    }

    public function fill(Model $m, array $data): void
    {
        $m->fill(collect($data)->except('approved')->all());
        $m->meta = $data['list_key'] === 'colour_status' ? ['approved' => (bool) ($data['approved'] ?? false)] : null;
    }
}
