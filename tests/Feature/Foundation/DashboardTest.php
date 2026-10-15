<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\Quality;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Database\Seeders\RolesAndPermissionsSeeder;

it('shows lot and contract stats', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create()->assignRole('viewer');
    $party = Party::create(['name' => 'OMBRE', 'type' => 'brand']);
    $q = Quality::fromOracle(1, '88X60/30X30 100% VISCOSE [AJ]');
    $c = Contract::create(['po_no' => 261000, 'party_id' => $party->id, 'po_date' => '2026-09-01', 'contract_qty' => 5000]);
    Lot::create(['oracle_line_id' => 1, 'lot_no_oracle' => '265000', 'contract_id' => $c->id, 'quality_id' => $q->id, 'po_type_oracle' => 'DYED', 'party_oracle_name' => $c->party->name, 'po_no' => $c->po_no, 'date_oracle' => $c->po_date, 'quality' => $q->description, 'greige_received_oracle' => 3000]);
    Lot::create(['oracle_line_id' => 2, 'lot_no_oracle' => '265001', 'contract_id' => $c->id, 'quality_id' => $q->id, 'po_type_oracle' => 'DYED', 'party_oracle_name' => $c->party->name, 'po_no' => $c->po_no, 'date_oracle' => $c->po_date, 'quality' => $q->description, 'greige_received_oracle' => 2000, 'oracle_missing_at' => now()]);
    Lot::create(['oracle_line_id' => 3, 'lot_no_oracle' => '265002', 'contract_id' => $c->id, 'quality_id' => $q->id, 'po_type_oracle' => 'DYED', 'party_oracle_name' => $c->party->name, 'po_no' => $c->po_no, 'date_oracle' => $c->po_date, 'quality' => $q->description, 'greige_received_oracle' => 999, 'state' => 'closed']);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page->component('Dashboard')
        ->where('stats.open_lots', 2)->where('stats.open_contracts', 1)->where('stats.open_greige_qty', 5000)
        ->where('stats.missing_from_oracle', 1)->where('lastImport', null));
});
