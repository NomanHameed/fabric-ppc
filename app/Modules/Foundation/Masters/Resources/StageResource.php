<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Stage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Stages master: create, rename, reorder and deactivate the reporting stages — and, since resolution
 * became stage-only, the status mapping itself. `status_aliases` is edited here, as a comma-separated list of
 * the raw statuses (`lots.status_oracle`) that mean this stage. `*` is a wildcard, so `DISP *` covers the
 * whole dispatch family and `*CANCEL*` anything cancelled; `#BLANK` claims a lot that carries no status at
 * all (everything still in greige stock).
 *
 * This is the one screen the Unmapped Statuses report sends the operator to, which is why both are gated on
 * `masters.manage`: seeing an unmapped status is only useful to someone who may map it.
 *
 * Deleting one is refused by MasterController while anything still points at it (its processes restrict the
 * delete, and the Oracle-Forms screen shows `forms.in_use`) — spec §18's "never delete something production
 * references; prefer deactivate".
 */
class StageResource extends MasterResource
{
    /** The separator between aliases in the form field (a comma: no status contains one). */
    private const ALIAS_SEPARATOR = ',';

    public function key(): string
    {
        return 'stages';
    }

    public function model(): string
    {
        return Stage::class;
    }

    public function defaultSort(): string
    {
        return 'sort_order';
    }

    /** @return list<Field> */
    public function fields(): array
    {
        return [
            Field::make('code')->required()->span(2),
            Field::make('name')->required()->span(4),
            Field::make('description')->span(5)->grid(false),
            Field::make('sort_order', 'number')->required()->span(1),
            Field::make('is_active', 'bool')->span(1),
            // The mapping. Queryable as text, so "which stage claims DYEING?" is an F7/F8 away.
            Field::make('status_aliases')->label('masters.fields.status_aliases')->span(12),
        ];
    }

    /**
     * The aliases are stored as JSON and edited as one comma-separated line.
     *
     * @return array<string, mixed>
     */
    public function toRow(Model $m): array
    {
        return array_merge(parent::toRow($m), [
            'status_aliases' => $m instanceof Stage ? implode(', ', $m->aliasList()) : '',
        ]);
    }

    /** @param array<string, mixed> $data */
    public function fill(Model $m, array $data): void
    {
        $m->fill(collect($data)->except('status_aliases')->all());
        $m->setAttribute('status_aliases', self::parseAliases($data['status_aliases'] ?? null));
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

    /** Same reason as StageProcessResource: `lots.stage_id` is nullOnDelete, so the FK would not refuse it. */
    public function assertDeletable(Model $m): void
    {
        if ($m instanceof Stage && $m->lots()->exists()) {
            throw ValidationException::withMessages(['record' => __('forms.in_use')]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('stages', 'code')->ignore($record?->getKey())],
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'status_aliases' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }
}
