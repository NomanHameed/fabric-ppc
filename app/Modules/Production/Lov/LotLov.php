<?php

namespace App\Modules\Production\Lov;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Production\Models\Lot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LotLov implements LovDefinition
{
    public function name(): string
    {
        return 'lots';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => __('oracle.fields.lot_no')],
            ['key' => 'po_no', 'label' => __('oracle.fields.po_no')],
            ['key' => 'party', 'label' => __('oracle.fields.party')],
            ['key' => 'design', 'label' => __('oracle.fields.design')],
            ['key' => 'colour', 'label' => __('oracle.fields.colour')],
            ['key' => 'quality', 'label' => __('oracle.fields.quality')],
        ];
    }

    public function query(array $filters): Builder
    {
        return Lot::query()->open()->with(['contract.party', 'quality'])
            ->join('contracts', 'contracts.id', '=', 'lots.contract_id')->select('lots.*');
    }

    public function searchColumns(): array
    {
        return ['lots.lot_no', 'contracts.po_no', 'lots.design', 'lots.colour'];
    }

    public function exactColumn(): string
    {
        return 'lots.lot_no';
    }

    /** Exact: full lot no., or the last 4 digits as written on floor sheets. */
    public function applyExact(Builder $query, string $value): void
    {
        strlen($value) <= 4 && ctype_digit($value)
            ? $query->where('lots.lot_no', 'like', '%'.$value)
            : $query->where('lots.lot_no', $value);
    }

    public function row(Model $m): array
    {
        return [
            'id' => $m->id, 'code' => $m->lot_no,
            'label' => "{$m->lot_no} · {$m->contract->po_no} · {$m->design} · {$m->colour}",
            'po_no' => $m->contract->po_no, 'party' => $m->contract->party->name, 'design' => $m->design,
            'colour' => $m->colour, 'quality' => $m->quality->description,
            'g_width' => $m->g_width, 'f_width' => $m->f_width,
        ];
    }
}
