<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Stage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Stages master: create, rename, reorder and deactivate the reporting stages.
 *
 * Deleting one is refused by MasterController while anything still points at it (its processes restrict the
 * delete, and the Oracle-Forms screen shows `forms.in_use`) — spec §18's "never delete something production
 * references; prefer deactivate".
 */
class StageResource extends MasterResource
{
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
        ];
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
            'is_active' => ['boolean'],
        ];
    }
}
