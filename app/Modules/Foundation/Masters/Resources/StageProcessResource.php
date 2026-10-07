<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Stage Processes master — and with it the status mapping itself: `status_aliases` is edited here, as a
 * comma-separated list of the raw statuses (`lots.current_label`) that mean this process. `*` is a wildcard,
 * so `DISP *` covers the whole dispatch family and `*CANCEL*` anything cancelled.
 *
 * This is the one screen the Unmapped Statuses report sends the operator to, which is why both are gated on
 * `masters.manage`: seeing an unmapped status is only useful to someone who may map it.
 */
class StageProcessResource extends MasterResource
{
    /** The separator between aliases in the form field (a comma: no status contains one). */
    private const ALIAS_SEPARATOR = ',';

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
            // The mapping. Queryable as text, so "which process claims DYEING?" is an F7/F8 away.
            Field::make('status_aliases')->label('masters.fields.status_aliases')->span(12),
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
            'status_aliases' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * `lots.stage_process_id` is nullOnDelete, so the database would let a referenced process go and empty
     * the column on every lot pointing at it — a silent change to figures nobody asked to change. Refused
     * here instead: a process in use is deactivated (spec §18), which keeps the lots' history readable.
     */
    public function assertDeletable(Model $m): void
    {
        if ($m instanceof StageProcess && $m->lots()->exists()) {
            throw ValidationException::withMessages(['record' => __('forms.in_use')]);
        }
    }

    /**
     * The aliases are stored as JSON and edited as one comma-separated line.
     *
     * @return array<string, mixed>
     */
    public function toRow(Model $m): array
    {
        return array_merge(parent::toRow($m), [
            // `stage_id` is a select, so the grid resolves the stage's name from the field's own options.
            'status_aliases' => $m instanceof StageProcess ? implode(', ', $m->aliasList()) : '',
        ]);
    }

    /** @param array<string, mixed> $data */
    public function fill(Model $m, array $data): void
    {
        $m->fill(collect($data)->except('status_aliases')->all());
        $m->setAttribute('status_aliases', self::parseAliases($data['status_aliases'] ?? null));
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

    /**
     * "DISPATCHED, RF-DISP, DISP *" -> ['DISPATCHED', 'RF-DISP', 'DISP *'], blanks and repeats dropped. The
     * aliases are kept as the operator typed them (the resolver normalises when it compares), so the screen
     * shows back exactly what was entered.
     *
     * @return list<string>
     */
    private static function parseAliases(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $aliases = array_filter(array_map('trim', explode(self::ALIAS_SEPARATOR, $value)), fn (string $a): bool => $a !== '');

        return array_values(array_unique($aliases));
    }
}
