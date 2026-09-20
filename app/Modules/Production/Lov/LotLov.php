<?php

namespace App\Modules\Production\Lov;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Production\Models\Lot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

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

    /** Open lots; `in_stock=1` keeps only those with greige still in stock (Issue to Floor). */
    public function query(array $filters): Builder
    {
        return Lot::query()->open()->with(['contract.party', 'quality'])
            ->join('contracts', 'contracts.id', '=', 'lots.contract_id')->select('lots.*')
            ->when(filter_var($filters['in_stock'] ?? false, FILTER_VALIDATE_BOOL), fn ($q) => $q->where('lots.stock_qty', '>', 0));
    }

    public function searchColumns(): array
    {
        return ['lots.lot_no', 'contracts.po_no', 'lots.design', 'lots.colour'];
    }

    public function exactColumn(): string
    {
        return 'lots.lot_no';
    }

    /** Exact: the full lot no., the last 4 digits as written on floor sheets, or a PO number (spec §6.3). */
    public function applyExact(Builder $query, string $value): void
    {
        if (! ctype_digit($value)) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (strlen($value) <= 4) {
            $query->where('lots.lot_no', 'like', '%'.$value);

            return;
        }
        $query->where(fn ($q) => $q->where('lots.lot_no', $value)->orWhere('contracts.po_no', (int) $value));
    }

    /** Beyond the columns(): the balances and state a picking screen needs (Issue to Floor shows what is in stock). */
    public function row(Model $m): array
    {
        if (! $m instanceof Lot) {
            throw new InvalidArgumentException('LotLov rows are lots.');
        }

        return [
            'id' => $m->id, 'code' => $m->lot_no,
            'label' => "{$m->lot_no} · {$m->contract->po_no} · {$m->design} · {$m->colour}",
            'po_no' => $m->contract->po_no, 'party' => $m->contract->party->name, 'design' => $m->design,
            'colour' => $m->colour, 'quality' => $m->quality->description,
            'g_width' => $m->g_width === null ? null : (float) $m->g_width, 'f_width' => $m->f_width === null ? null : (float) $m->f_width,
            'stock_qty' => (float) $m->stock_qty, 'wip_qty' => (float) $m->wip_qty, 'finished_qty' => (float) $m->finished_qty,
            'state' => $m->state, 'current_label' => $m->current_label, 'contract_id' => $m->contract_id,
        ];
    }
}
