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
    // The denormalised copies the report prints without joining are NOT NULL on `lots`, so a lot created
    // straight through the model (rather than through LotFactory) states them, as the import does.
    $base = [
        'quality_id' => $quality->id, 'po_type_oracle' => 'DYED',
        'party_oracle_id' => $party->oracle_code, 'party_oracle_name' => $party->name,
        'date_oracle' => '2026-08-25', 'quality' => $quality->description,
    ];
    Lot::create($base + ['oracle_line_id' => 47119, 'lot_no_oracle' => '264899', 'contract_id' => $c1->id, 'po_no' => $c1->po_no, 'design' => 'E1143/119', 'color_oracle_name' => '520 PLUM PURPLE', 'greige_received_oracle' => 86]);
    Lot::create($base + ['oracle_line_id' => 47120, 'lot_no_oracle' => '264899', 'contract_id' => $c2->id, 'po_no' => $c2->po_no, 'design' => 'E1295/104', 'color_oracle_name' => '121 MID OLIVE', 'greige_received_oracle' => 1065]);
    Lot::create($base + ['oracle_line_id' => 47090, 'lot_no_oracle' => '265025', 'contract_id' => $c2->id, 'po_no' => $c2->po_no, 'design' => 'D1', 'color_oracle_name' => 'BLACK', 'greige_received_oracle' => 3650]);
    Lot::create($base + ['oracle_line_id' => 40000, 'lot_no_oracle' => '255025', 'contract_id' => $c2->id, 'po_no' => $c2->po_no, 'design' => 'OLD', 'color_oracle_name' => 'RED', 'greige_received_oracle' => 10, 'state' => 'closed']);
    Lot::create($base + ['oracle_line_id' => 40001, 'lot_no_oracle' => '245025', 'contract_id' => $c2->id, 'po_no' => $c2->po_no, 'design' => 'HELD', 'color_oracle_name' => 'GREY', 'greige_received_oracle' => 10, 'state' => 'hold']);
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

it('resolves a PO number to every open lot of that PO (M-13)', function () {
    $res = $this->actingAs($this->user)->getJson('/lov/lots?exact=261072')->assertOk();
    expect(collect($res->json('data'))->pluck('code')->sort()->values()->all())->toBe(['264899', '265025']);
});

it('returns nothing for a non-numeric exact needle (M-13)', function () {
    expect($this->actingAs($this->user)->getJson('/lov/lots?exact=black')->assertOk()->json('data'))->toBe([]);
});

it('searches by design or PO', function () {
    expect($this->actingAs($this->user)->getJson('/lov/lots?q=olive')->json('data'))->toHaveCount(1)
        ->and($this->actingAs($this->user)->getJson('/lov/lots?q=261072')->json('data'))->toHaveCount(2);
});

it('includes held lots only when filters[states] asks for them (R1)', function () {
    $codes = fn (string $query) => collect($this->actingAs($this->user)->getJson('/lov/lots?exact=5025'.$query)->assertOk()->json('data'))->pluck('code')->sort()->values()->all();

    expect($codes(''))->toBe(['265025'])
        ->and($codes('&filters[states]=open'))->toBe(['265025'])
        ->and($codes('&filters[states]=open,hold'))->toBe(['245025', '265025'])
        ->and($codes('&filters[states]=hold'))->toBe(['245025'])
        ->and($codes('&filters[states]=open,hold,closed'))->toBe(['245025', '255025', '265025']);
});

it('falls back to open lots only for a junk states filter', function () {
    $codes = fn (string $query) => collect($this->actingAs($this->user)->getJson('/lov/lots?exact=5025'.$query)->assertOk()->json('data'))->pluck('code')->all();

    expect($codes('&filters[states]=junk'))->toBe(['265025'])
        ->and($codes('&filters[states]=junk,also'))->toBe(['265025'])
        ->and($codes('&filters[states][]=hold'))->toBe(['265025']);
});

it('lists contracts', function () {
    expect($this->actingAs($this->user)->getJson('/lov/contracts?exact=261071')->json('data.0.code'))->toBe('261071');
});

it('enforces unique oracle line ids', function () {
    Lot::factory()->create(['oracle_line_id' => 47090, 'lot_no_oracle' => '1', 'contract_id' => Contract::first()->id,
        'quality_id' => Quality::first()->id, 'po_type_oracle' => 'DYED', 'greige_received_oracle' => 1]);
})->throws(UniqueConstraintViolationException::class);
