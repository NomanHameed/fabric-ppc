<?php

namespace App\Modules\Production\Lov;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Production\Models\Contract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContractLov implements LovDefinition
{
    public function name(): string
    {
        return 'contracts';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => __('oracle.fields.po_no')],
            ['key' => 'ref_no', 'label' => __('oracle.fields.ref_no')],
            ['key' => 'party', 'label' => __('oracle.fields.party')],
        ];
    }

    /** With the open lots' count and greige in stock as subselects, so the PO block can show what a bulk issue covers. */
    public function query(array $filters): Builder
    {
        return Contract::query()->with('party')
            ->withCount(['lots as open_lots' => fn ($q) => $q->where('state', 'open')->where('greige_in_stock_oracle', '>', 0)])
            ->withSum(['lots as open_stock_qty' => fn ($q) => $q->where('state', 'open')], 'greige_in_stock_oracle');
    }

    public function searchColumns(): array
    {
        return ['po_no', 'ref_no'];
    }

    public function exactColumn(): string
    {
        return 'po_no';
    }

    public function row(Model $m): array
    {
        return [
            'id' => $m->id, 'code' => (string) $m->po_no, 'label' => (string) $m->po_no, 'ref_no' => $m->ref_no, 'party' => $m->party->name,
            'open_lots' => (int) $m->getAttribute('open_lots'), 'greige_in_stock_oracle' => (float) ($m->getAttribute('open_stock_qty') ?? 0),
        ];
    }
}
