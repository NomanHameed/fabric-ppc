<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\Party;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PartyLov implements LovDefinition
{
    public function name(): string
    {
        return 'parties';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function columns(): array
    {
        return [
            ['key' => 'label', 'label' => __('masters.fields.name')],
            ['key' => 'oracle_code', 'label' => __('masters.fields.oracle_code')],
            ['key' => 'type', 'label' => __('masters.fields.type')],
        ];
    }

    public function query(array $filters): Builder
    {
        return Party::query()->where('is_active', true);
    }

    public function searchColumns(): array
    {
        return ['name_normalized'];
    }

    /** Exact matching on the normalised name; aliases are handled by the override below. */
    public function exactColumn(): string
    {
        return 'name_normalized';
    }

    /** Exact match by name or alias, resolved through Party::findByName. */
    public function applyExact(Builder $query, string $value): void
    {
        $query->whereKey(Party::findByName($value)?->id ?? 0);
    }

    public function row(Model $m): array
    {
        return ['id' => $m->id, 'code' => (string) ($m->oracle_code ?? $m->id), 'label' => $m->name, 'oracle_code' => $m->oracle_code, 'type' => $m->type];
    }
}
