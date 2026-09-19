<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\Quality;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $party = Party::create(['name' => 'OMBRE', 'type' => 'brand', 'oracle_code' => 518]);
    $quality = Quality::fromOracle(2911, '0X0/0X0 100% VISCOSE [AJ] SATIN');
    $c1 = Contract::create(['po_no' => 261071, 'party_id' => $party->id, 'po_date' => '2026-08-25', 'contract_qty' => 86]);
    $c2 = Contract::create(['po_no' => 261072, 'party_id' => $party->id, 'po_date' => '2026-08-25', 'contract_qty' => 1065]);
    $base = ['quality_id' => $quality->id, 'po_type' => 'DYED'];
    Lot::create($base + ['oracle_line_id' => 47119, 'lot_no' => '264899', 'contract_id' => $c1->id, 'design' => 'E1143/119', 'colour' => '520 PLUM PURPLE', 'greige_qty' => 86]);
    Lot::create($base + ['oracle_line_id' => 47120, 'lot_no' => '264899', 'contract_id' => $c2->id, 'design' => 'E1295/104', 'colour' => '121 MID OLIVE', 'greige_qty' => 1065]);
    Lot::create($base + ['oracle_line_id' => 47090, 'lot_no' => '265025', 'contract_id' => $c2->id, 'design' => 'D1', 'colour' => 'BLACK', 'greige_qty' => 3650]);
    Lot::create($base + ['oracle_line_id' => 40000, 'lot_no' => '255025', 'contract_id' => $c2->id, 'design' => 'OLD', 'colour' => 'RED', 'greige_qty' => 10, 'state' => 'closed']);
});

it('returns every open lot sharing a lot number with PO context', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/lots?exact=264899')->assertOk();
    expect(collect($res->json('data'))->pluck('po_no')->sort()->values()->all())->toBe([261071, 261072])
        ->and($res->json('data.0.label'))->toContain('264899 · 2610');
});

it('resolves the last four digits to open lots only', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/lots?exact=5025')->assertOk();
    expect(collect($res->json('data'))->pluck('code')->all())->toBe(['265025']);
});

it('searches by design or PO', function () {
    expect($this->actingAs($this->user)->getJson('/lov/lots?q=olive')->json('data'))->toHaveCount(1)
        ->and($this->actingAs($this->user)->getJson('/lov/lots?q=261072')->json('data'))->toHaveCount(2);
});

it('lists contracts', function () {
    expect($this->actingAs($this->user)->getJson('/lov/contracts?exact=261071')->json('data.0.code'))->toBe('261071');
});

it('enforces unique oracle line ids', function () {
    Lot::create(['oracle_line_id' => 47090, 'lot_no' => '1', 'contract_id' => Contract::first()->id, 'quality_id' => Quality::first()->id, 'po_type' => 'DYED', 'greige_qty' => 1]);
})->throws(UniqueConstraintViolationException::class);
