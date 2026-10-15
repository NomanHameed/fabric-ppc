<?php

namespace App\Modules\Production\Lov;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Production\Models\Lot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class LotLov implements LovDefinition
{
    private const STATES = ['open', 'hold', 'closed', 'cancelled'];

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
            ['key' => 'code', 'label' => __('oracle.fields.lot_no_oracle')],
            ['key' => 'po_no', 'label' => __('oracle.fields.po_no')],
            ['key' => 'party', 'label' => __('oracle.fields.party')],
            ['key' => 'design', 'label' => __('oracle.fields.design')],
            ['key' => 'color_oracle_name', 'label' => __('oracle.fields.color_oracle_name')],
            ['key' => 'quality', 'label' => __('oracle.fields.quality')],
        ];
    }

    /**
     * Lots in the states named by `states` (comma-separated; open only by default, and whenever nothing valid is
     * asked for — the Lot Route screen asks for `open,hold`); `in_stock=1` keeps only those with greige still in
     * stock (Issue to Floor).
     */
    public function query(array $filters): Builder
    {
        $states = array_values(array_intersect(explode(',', (string) ($filters['states'] ?? 'open')), self::STATES)) ?: ['open'];

        return Lot::query()->whereIn('lots.state', $states)->with(['contract.party', 'stage'])
            ->join('contracts', 'contracts.id', '=', 'lots.contract_id')->select('lots.*')
            ->when(filter_var($filters['in_stock'] ?? false, FILTER_VALIDATE_BOOL), fn ($q) => $q->where('lots.greige_in_stock_oracle', '>', 0));
    }

    public function searchColumns(): array
    {
        return ['lots.lot_no_oracle', 'contracts.po_no', 'lots.design', 'lots.color_oracle_name'];
    }

    public function exactColumn(): string
    {
        return 'lots.lot_no_oracle';
    }

    /** Exact: the full lot no., the last 4 digits as written on floor sheets, or a PO number (spec §6.3). */
    public function applyExact(Builder $query, string $value): void
    {
        if (! ctype_digit($value)) {
            $query->whereRaw('1 = 0');

            return;
        }
        if (strlen($value) <= 4) {
            $query->where('lots.lot_no_oracle', 'like', '%'.$value);

            return;
        }
        $query->where(fn ($q) => $q->where('lots.lot_no_oracle', $value)->orWhere('contracts.po_no', (int) $value));
    }

    /** Beyond the columns(): the balances and state a picking screen needs (Issue to Floor shows what is in stock). */
    public function row(Model $m): array
    {
        if (! $m instanceof Lot) {
            throw new InvalidArgumentException('LotLov rows are lots.');
        }

        return [
            'id' => $m->id, 'code' => $m->lot_no_oracle,
            'label' => "{$m->lot_no_oracle} · {$m->contract->po_no} · {$m->design} · {$m->color_oracle_name}",
            'po_no' => $m->contract->po_no, 'party' => $m->contract->party->name, 'design' => $m->design,
            'color_oracle_name' => $m->color_oracle_name, 'quality' => $m->quality,
            'gw_oracle' => $m->gw_oracle === null ? null : (float) $m->gw_oracle, 'f_width' => $m->f_width === null ? null : (float) $m->f_width,
            'greige_in_stock_oracle' => (float) $m->greige_in_stock_oracle, 'wip' => (float) $m->wip, 'finished' => (float) $m->finished,
            'state' => $m->state, 'status_oracle' => $m->status_oracle, 'contract_id' => $m->contract_id,
            // The reporting taxonomy, for a picking screen that groups by stage rather than by raw status.
            'stage_id' => $m->stage_id, 'stage' => $m->stage?->name,
        ];
    }
}
