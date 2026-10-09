<?php

use App\Models\User;
use App\Modules\Production\Http\Controllers\ContractController;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * The POs / Contracts screen (Task 12, spec §4.4 screen 4, §4.2): the page props, query mode over the contract
 * and its joined party, the per-PO lot totals summed in the database (correct beyond the row cap), the ROW_CAP
 * page with the true match count, and the permission matrix.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->viewer = User::factory()->create()->assignRole('viewer');
    $this->contract = Contract::factory()->create(['po_no' => 261071, 'po_date' => '2026-08-17', 'ref_no' => 'REF-1', 'contract_qty' => 500]);
});

it('lists POs with their lot totals, summed in the database', function () {
    $a = Lot::factory()->create(['contract_id' => $this->contract->id, 'greige_received_oracle' => 100]);
    Lot::factory()->create(['contract_id' => $this->contract->id, 'greige_received_oracle' => 50]);
    app(LedgerService::class)->post($a, 'import', null, Position::stock(), 0, 100);

    $this->actingAs($this->viewer)->get('/production/contracts')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('production/Contracts')
            ->has('contracts', 1)
            ->where('contracts.0.id', $this->contract->id)
            ->where('contracts.0.po_no', 261071)
            ->where('contracts.0.party', $this->contract->party->name)
            ->where('contracts.0.ref_no', 'REF-1')
            ->where('contracts.0.po_date', '17-08-2026')
            ->where('contracts.0.contract_qty', 500)
            ->where('contracts.0.lots_count', 2)
            ->where('contracts.0.greige_received_oracle', 150)
            ->where('contracts.0.greige_in_stock_oracle', 100)
            ->where('contracts.0.qty_issued', 0)
            ->where('contracts.0.wip', 0)
            ->where('contracts.0.finished', 0)
            ->where('contracts.0.dispatch', 0)
            ->where('contracts.0.loss_qty', 0)
            ->where('contracts.0.oracle_missing_at', null)
            ->where('contractsTotal', 1));
});

it('lists a PO with no lots at all, with zero totals', function () {
    $this->actingAs($this->viewer)->get('/production/contracts')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 1)
            ->where('contracts.0.lots_count', 0)->where('contracts.0.greige_received_oracle', 0));
});

it('totals every lot of a PO, not only the ones a capped page would show', function () {
    $lot = Lot::factory()->create(['contract_id' => $this->contract->id, 'greige_received_oracle' => 7]);
    $rows = [];
    $now = now();
    for ($i = 0; $i < 250; $i++) {
        $rows[] = [
            'oracle_line_id' => 700000 + $i, 'lot_no_oracle' => (string) (500000 + $i), 'contract_id' => $this->contract->id,
            'quality_id' => $lot->quality_id, 'po_type_oracle' => 'DYED', 'greige_received_oracle' => 2, 'greige_in_stock_oracle' => 1,
            'state' => 'open', 'created_at' => $now, 'updated_at' => $now,
            'party_oracle_name' => $lot->party_oracle_name, 'po_no' => $lot->po_no,
            'date_oracle' => $lot->date_oracle, 'quality' => $lot->quality,
        ];
    }
    DB::table('lots')->insert($rows);

    $this->actingAs($this->viewer)->get('/production/contracts')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 1)
            ->where('contracts.0.lots_count', 251)
            ->where('contracts.0.greige_received_oracle', 507)   // 7 + 250 × 2
            ->where('contracts.0.greige_in_stock_oracle', 250));  // 250 × 1
});

it('totals every lot of a PO whatever state it is in', function () {
    // held, closed and cancelled lots are still part of the PO: their metres belong in its totals
    foreach (['open', 'hold', 'closed', 'cancelled'] as $state) {
        Lot::factory()->create(['contract_id' => $this->contract->id, 'greige_received_oracle' => 10, 'state' => $state]);
    }

    $this->actingAs($this->viewer)->get('/production/contracts')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 1)
            ->where('contracts.0.lots_count', 4)
            ->where('contracts.0.greige_received_oracle', 40));
});

it('filters by PO number, party, ref, PO date and the Oracle-missing flag', function () {
    $other = Contract::factory()->create(['po_no' => 999999, 'po_date' => '2026-01-05', 'ref_no' => 'ZZZ']);
    DB::table('contracts')->where('id', $other->id)->update(['oracle_missing_at' => '2026-09-01 00:00:00']);

    $this->actingAs($this->viewer)->get('/production/contracts?q[po_no]=261071')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 1)->where('contracts.0.po_no', 261071));
    $this->actingAs($this->viewer)->get('/production/contracts?q[po_no]=>500000')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 1)->where('contracts.0.po_no', 999999));
    $party = mb_substr($this->contract->party->name, 0, 3).'%';
    $this->actingAs($this->viewer)->get('/production/contracts?q[party]='.urlencode($party))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 1)->where('contracts.0.po_no', 261071));
    $this->actingAs($this->viewer)->get('/production/contracts?q[party]=zzz-no-such-party')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 0));
    $this->actingAs($this->viewer)->get('/production/contracts?q[ref_no]=REF%25')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 1)->where('contracts.0.ref_no', 'REF-1'));
    $this->actingAs($this->viewer)->get('/production/contracts?q[po_date]=01-08-2026..31-08-2026')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 1)->where('contracts.0.po_no', 261071));
    // #NULL and a range both work on the Oracle-missing timestamp
    $this->actingAs($this->viewer)->get('/production/contracts?q[oracle_missing_at]=%23NULL')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 1)->where('contracts.0.po_no', 261071));
    $this->actingAs($this->viewer)->get('/production/contracts?q[oracle_missing_at]=>=01-01-2026')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 1)
            ->where('contracts.0.po_no', 999999)->where('contracts.0.oracle_missing_at', '01-09-2026'));
});

it('matches the Oracle-missing timestamp by the day and by a day range', function () {
    // the column is a timestamp; a PO flagged at 14:30 must still answer the plain day it was flagged on
    $midday = Contract::factory()->create(['po_no' => 900001, 'po_date' => '2026-02-02']);
    $monthEnd = Contract::factory()->create(['po_no' => 900002, 'po_date' => '2026-02-03']);
    DB::table('contracts')->where('id', $midday->id)->update(['oracle_missing_at' => '2026-09-01 14:30:00']);
    DB::table('contracts')->where('id', $monthEnd->id)->update(['oracle_missing_at' => '2026-09-30 09:00:00']);

    $this->actingAs($this->viewer)->get('/production/contracts?q[oracle_missing_at]=01-09-2026')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 1)->where('contracts.0.po_no', 900001));
    // the range covers both end days in full, so 30-09 09:00 is inside 01-09..30-09
    $this->actingAs($this->viewer)->get('/production/contracts?q[oracle_missing_at]=01-09-2026..30-09-2026')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', 2));
});

it('rejects an unparseable and an array-valued criterion with a field error', function () {
    $this->actingAs($this->viewer)->get('/production/contracts?q[po_no]=>abc')->assertSessionHasErrors('po_no');
    $this->actingAs($this->viewer)->get('/production/contracts?q[po_date]=31-31-2026')->assertSessionHasErrors('po_date');
    $this->actingAs($this->viewer)->get('/production/contracts?q[party][]=x')->assertSessionHasErrors('party');
});

it('caps the grid at ROW_CAP POs but reports the true number of matches', function () {
    $party = $this->contract->party_id;
    $rows = [];
    $now = now();
    for ($i = 0; $i < 210; $i++) {
        $rows[] = [
            'po_no' => 800000 + $i, 'party_id' => $party, 'po_date' => '2026-05-05', 'contract_qty' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }
    DB::table('contracts')->insert($rows);

    $this->actingAs($this->viewer)->get('/production/contracts')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('contracts', ContractController::ROW_CAP)->where('contractsTotal', 211));
});

it('opens for every seeded role (all have inquiry.view)', function () {
    foreach (['admin', 'ppc', 'manager', 'dept_incharge', 'colour_lab', 'dispatch', 'viewer'] as $role) {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user)->get('/production/contracts')->assertOk();
    }
});

it('redirects a guest to the login page', function () {
    $this->get('/production/contracts')->assertRedirect('/login');
});

it('has no write route: the screen is read-only', function () {
    $ppc = User::factory()->create()->assignRole('ppc');
    $this->actingAs($ppc)->put("/production/contracts/{$this->contract->id}", ['ref_no' => 'X'])->assertNotFound();
    $this->actingAs($ppc)->delete("/production/contracts/{$this->contract->id}")->assertNotFound();
    $this->actingAs($ppc)->post('/production/contracts', ['po_no' => 1])->assertStatus(405); // GET only
});
