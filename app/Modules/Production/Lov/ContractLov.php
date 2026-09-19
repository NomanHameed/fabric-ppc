<?php

namespace App\Modules\Production\Lov;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Production\Models\Contract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ContractLov implements LovDefinition
{
    public function name(): string { return 'contracts'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => __('oracle.fields.po_no')],
            ['key' => 'ref_no', 'label' => __('oracle.fields.ref_no')],
            ['key' => 'party', 'label' => __('oracle.fields.party')],
        ];
    }

    public function query(array $filters): Builder { return Contract::query()->with('party'); }

    public function searchColumns(): array { return ['po_no', 'ref_no']; }

    public function exactColumn(): string { return 'po_no'; }

    public function row(Model $m): array
    {
        return ['id' => $m->id, 'code' => (string) $m->po_no, 'label' => (string) $m->po_no, 'ref_no' => $m->ref_no, 'party' => $m->party->name];
    }
}
