<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * The Stage Processes master: the mill's own naming of the positions inside a stage.
 *
 * **It no longer carries the status mapping.** Resolution is stage-only (`lots.stage_process_id` is gone),
 * so `status_aliases` moved to the Stages master and is not edited here — a field that could not change what
 * the report groups by would be worse than no field. The column survives on the table, unread, so the move
 * stays reversible.
 */
class StageProcessResource extends MasterResource
{
    public function key(): string
    {
        return 'stage_processes';
    }

    public function model(): string
    {
        return StageProcess::class;
    }

    public function with(): array
    {
        return ['stage'];
    }

    public function defaultSort(): string
    {
        return 'stage_id';
    }

    /** @return list<Field> */
    public function fields(): array
    {
        return [
            Field::make('stage_id', 'select')->label('masters.fields.stage')->required()
                ->options(self::stageOptions())->queryType('number')->span(3),
            Field::make('code')->required()->span(2),
            Field::make('name')->required()->span(4),
            Field::make('sort_order', 'number')->required()->span(1),
            Field::make('is_active', 'bool')->span(1),
            Field::make('description')->span(6)->grid(false),
            Field::make('quantity_type', 'select')->options(self::quantityOptions())->span(2),
            Field::make('is_wip', 'bool')->span(1),
            Field::make('is_finished', 'bool')->span(1),
            Field::make('is_dispatch', 'bool')->span(1),
        ];
    }

    /** @return array<string, list<mixed>> */
    public function rules(?Model $record): array
    {
        return [
            'stage_id' => ['required', 'integer', 'exists:stages,id'],
            'code' => ['required', 'string', 'max:40', 'alpha_dash',
                Rule::unique('stage_processes', 'code')->where('stage_id', request('stage_id'))->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'quantity_type' => ['nullable', Rule::in(StageProcess::QUANTITY_TYPES)],
            'is_wip' => ['boolean'],
            'is_finished' => ['boolean'],
            'is_dispatch' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Every stage, in its own order, as the select's options.
     *
     * @return list<array{value: int, label: string}>
     */
    private static function stageOptions(): array
    {
        $options = [];
        foreach (Stage::query()->orderBy('sort_order')->orderBy('code')->get() as $stage) {
            $options[] = ['value' => (int) $stage->getKey(), 'label' => (string) $stage->getAttribute('name')];
        }

        return $options;
    }

    /**
     * "no quantity" plus one option per quantity kind, labelled from the masters lang group.
     *
     * @return list<array{value: string|null, label: string}>
     */
    private static function quantityOptions(): array
    {
        $options = [['value' => null, 'label' => '—']];
        foreach (StageProcess::QUANTITY_TYPES as $type) {
            $options[] = ['value' => $type, 'label' => (string) __('masters.options.quantity_'.$type)];
        }

        return $options;
    }
}
