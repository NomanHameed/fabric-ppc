<?php

use App\Models\User;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Production\Http\Controllers\LotController;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * The Lots screen (Task 12, spec §4.4 screen 3, §4.2, §5.3): the page props, query mode over the lot and its
 * joined PO / party / quality, the ROW_CAP page with the true match count, the two editable fields behind their
 * own permissions, and the read-only guarantee for everything Oracle or the ledger owns.
 */

beforeEach(function () {
    config(['audit.console' => true]); // tests run in the console; the update must still be audited
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->viewer = User::factory()->create()->assignRole('viewer');
    $this->colourLab = User::factory()->create()->assignRole('colour_lab');
    $this->deptIncharge = User::factory()->create()->assignRole('dept_incharge');
    $this->contract = Contract::factory()->create(['po_no' => 261071, 'po_date' => '2026-08-17', 'ref_no' => 'REF-1']);
    $this->open = Lot::factory()->create([
        'contract_id' => $this->contract->id, 'lot_no_oracle' => '264899', 'design' => 'E1143/119', 'color_oracle_name' => '520 PLUM',
        'po_type_oracle' => 'DYED', 'greige_received_oracle' => 300, 'clr_status_oracle' => 'DIP',
    ]);
    $this->closed = Lot::factory()->create(['contract_id' => $this->contract->id, 'lot_no_oracle' => '255025', 'state' => 'closed', 'design' => 'Z900']);
});

// ---- index ----------------------------------------------------------------------------------------------------

it('lists the open work by default with the full record shape', function () {
    app(LedgerService::class)->post($this->open, 'import', null, Position::stock(), 0, 300);

    $this->actingAs($this->viewer)->get('/production/lots')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('production/Lots')
            ->has('lots', 1)
            ->where('lots.0.id', $this->open->id)
            ->where('lots.0.lot_no_oracle', '264899')
            ->where('lots.0.po_no', 261071)
            ->where('lots.0.contract_id', $this->contract->id)
            ->where('lots.0.party', $this->contract->party->name)
            ->where('lots.0.ref_no', 'REF-1')
            ->where('lots.0.po_date', '17-08-2026')
            ->where('lots.0.quality', $this->open->quality)
            ->where('lots.0.design', 'E1143/119')
            ->where('lots.0.color_oracle_name', '520 PLUM')
            ->where('lots.0.clr_status_oracle', 'DIP')
            ->where('lots.0.po_type_oracle', 'DYED')
            ->where('lots.0.state', 'open')
            ->where('lots.0.process_flag', 'pending')
            ->where('lots.0.greige_received_oracle', 300)
            ->where('lots.0.greige_in_stock_oracle', 300)
            ->where('lots.0.qty_issued', 0)
            ->where('lots.0.wip', 0)
            ->where('lots.0.finished', 0)
            ->where('lots.0.dispatch', 0)
            ->where('lots.0.loss_qty', 0)
            ->where('lotsTotal', 1)
            ->where('can.colour', false)->where('can.remarks', false)->where('can.hold', false)
            ->has('colourStatuses')->has('holdReasons'));
});

it('includes held lots in the default query and hides closed and cancelled ones', function () {
    $held = Lot::factory()->create(['contract_id' => $this->contract->id, 'lot_no_oracle' => '300001', 'state' => 'hold']);
    Lot::factory()->create(['contract_id' => $this->contract->id, 'lot_no_oracle' => '300002', 'state' => 'cancelled']);

    $this->actingAs($this->viewer)->get('/production/lots')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 2)
            ->where('lotsTotal', 2)
            ->where('lots.1.id', $held->id));
});

it('keeps the default query when every criterion box was left blank', function () {
    // F8 with nothing typed sends `q[lot_no_oracle]=`; that is not a criterion, so the open work is still what shows
    $this->actingAs($this->viewer)->get('/production/lots?q[lot_no_oracle]=')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 1)
            ->where('lots.0.lot_no_oracle', '264899')->where('lotsTotal', 1));
    // one real criterion drops the default and reaches the closed lot
    $this->actingAs($this->viewer)->get('/production/lots?q[state]=closed')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 1)
            ->where('lots.0.lot_no_oracle', '255025')->where('lotsTotal', 1));
});

it('filters on the lot columns, including wildcards and the status label', function () {
    // the label column belongs to LedgerService; a plain builder update keeps the test out of its way
    DB::table('lots')->where('id', $this->closed->id)->update(['status_oracle' => 'CLOSED/']);

    $this->actingAs($this->viewer)->get('/production/lots?q[state]=closed')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 1)->where('lots.0.lot_no_oracle', '255025'));
    $this->actingAs($this->viewer)->get('/production/lots?q[design]=e1143%25')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 1)->where('lots.0.lot_no_oracle', '264899'));
    $this->actingAs($this->viewer)->get('/production/lots?q[clr_status_oracle]=DIP')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 1));
    $this->actingAs($this->viewer)->get('/production/lots?q[status_oracle]=CLOSED%2F')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 1)->where('lots.0.lot_no_oracle', '255025'));
    $this->actingAs($this->viewer)->get('/production/lots?q[po_type_oracle]=HALF%20BLEACH')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 0));
});

it('filters on the joined PO, party and quality columns and on a date range', function () {
    $other = Contract::factory()->create(['po_no' => 999999, 'po_date' => '2026-01-05']);
    Lot::factory()->create(['contract_id' => $other->id, 'lot_no_oracle' => '270000']);

    // Party name, with a wildcard. The needle is only the first three characters, and ContractFactory names
    // parties with faker's unique()->company() — unique in full, but two company names readily share a
    // three-letter prefix ("Herman…" / "Hermann…"), so this assertion failed for whichever faker sequence the
    // full suite happened to produce. Pin both names so the prefix is unambiguous. LOT_CRITERIA filters the
    // lot's denormalised `party_oracle_name`, and LotFactory warns that column must agree with the contract's
    // party, so both are set together.
    foreach ([[$this->contract, 'Alpha Textiles'], [$other, 'Zeta Mills']] as [$contract, $partyName]) {
        DB::table('parties')->where('id', $contract->party_id)->update(['name' => $partyName]);
        DB::table('lots')->where('contract_id', $contract->id)->update(['party_oracle_name' => $partyName]);
    }

    $needle = 'Alpha Textiles';
    $this->actingAs($this->viewer)->get('/production/lots?q[party]='.urlencode(mb_substr($needle, 0, 3).'%'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 2)); // both lots of that party's PO
    $this->actingAs($this->viewer)->get('/production/lots?q[party]=zzz-no-such-party')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 0));
    // joined quality description: every factory lot carries the same description, so all three match
    $this->actingAs($this->viewer)->get('/production/lots?q[quality]='.urlencode('%VISCOSE%'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 3));
    // joined PO number, as a number range and with an operator (both lots of PO 261071)
    $this->actingAs($this->viewer)->get('/production/lots?q[po_no]=261000..262000')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 2));
    $this->actingAs($this->viewer)->get('/production/lots?q[po_no]=>500000')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 1)->where('lots.0.lot_no_oracle', '270000'));
    // joined PO date, as a date range
    $this->actingAs($this->viewer)->get('/production/lots?q[po_date]=01-08-2026..31-08-2026')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 2)->where('lots.0.po_no', 261071));
});

it('rejects an unparseable and an array-valued criterion with a field error', function () {
    $this->actingAs($this->viewer)->get('/production/lots?q[po_no]=>abc')->assertSessionHasErrors('po_no');
    $this->actingAs($this->viewer)->get('/production/lots?q[po_date]=31-31-2026')->assertSessionHasErrors('po_date');
    $this->actingAs($this->viewer)->get('/production/lots?q[lot_no_oracle][]=x')->assertSessionHasErrors('lot_no_oracle');
});

it('caps the grid at ROW_CAP rows but reports the true number of matches', function () {
    $rows = [];
    $now = now();
    // straight inserts: 210 extra lots through the factory would be far slower than this screen test needs
    for ($i = 0; $i < 210; $i++) {
        $rows[] = [
            'oracle_line_id' => 900000 + $i, 'lot_no_oracle' => (string) (400000 + $i), 'contract_id' => $this->contract->id,
            'quality_id' => $this->open->quality_id, 'po_type_oracle' => 'DYED', 'greige_received_oracle' => 10, 'state' => 'open',
            'created_at' => $now, 'updated_at' => $now,
            'party_oracle_name' => $this->open->party_oracle_name, 'po_no' => $this->open->po_no,
            'date_oracle' => $this->open->date_oracle, 'quality' => $this->open->quality,
        ];
    }
    DB::table('lots')->insert($rows);

    $this->actingAs($this->viewer)->get('/production/lots')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', LotController::ROW_CAP)->where('lotsTotal', 211));
});

// ---- update ---------------------------------------------------------------------------------------------------

it('lets colour.edit change the colour status and production.route.manage the remarks, and audits it', function () {
    $this->actingAs($this->ppc)->put("/production/lots/{$this->open->id}", ['clr_status_oracle' => 'APPROVED', 'remarks' => 'urgent'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $lot = $this->open->fresh();
    expect($lot->clr_status_oracle)->toBe('APPROVED')
        ->and($lot->remarks)->toBe('urgent')
        ->and($lot->audits()->where('event', 'updated')->count())->toBe(1);
});

it('rejects a colour status that is not an active lookup code', function () {
    $this->actingAs($this->ppc)->put("/production/lots/{$this->open->id}", ['clr_status_oracle' => 'NOPE'])
        ->assertSessionHasErrors('clr_status_oracle');
    LookupValue::query()->where('list_key', 'colour_status')->where('code', 'SHADE')->update(['is_active' => false]);
    $this->actingAs($this->ppc)->put("/production/lots/{$this->open->id}", ['clr_status_oracle' => 'SHADE'])
        ->assertSessionHasErrors('clr_status_oracle');
    expect($this->open->fresh()->clr_status_oracle)->toBe('DIP');
});

it('still accepts the unchanged colour status after its lookup value was deactivated', function () {
    LookupValue::query()->where('list_key', 'colour_status')->where('code', 'DIP')->update(['is_active' => false]);

    $this->actingAs($this->ppc)->put("/production/lots/{$this->open->id}", ['clr_status_oracle' => 'DIP', 'remarks' => 'still DIP'])
        ->assertSessionHasNoErrors();
    expect($this->open->fresh()->remarks)->toBe('still DIP');
});

it('ignores every Oracle-owned and ledger-derived field sent with the update', function () {
    $before = $this->open->fresh();
    $payload = ['remarks' => 'only this'] + array_fill_keys(Lot::ORACLE_FIELDS, 1) + [
        'lot_no_oracle' => '999999', 'po_type_oracle' => 'PRINTED', 'greige_received_oracle' => 99, 'design' => 'HACKED', 'contract_id' => 99999,
        'greige_in_stock_oracle' => 99, 'qty_issued' => 99, 'wip' => 99, 'finished' => 99, 'dispatch' => 99,
        'loss_qty' => 99, 'status_oracle' => 'HACKED/', 'state' => 'cancelled', 'oracle_line_id' => 42,
    ];
    $this->actingAs($this->ppc)->put("/production/lots/{$this->open->id}", $payload)->assertSessionHasNoErrors();

    $after = $this->open->fresh();
    expect($after->remarks)->toBe('only this')
        ->and($after->clr_status_oracle)->toBe('DIP'); // not sent, so not cleared
    foreach ([...Lot::ORACLE_FIELDS, 'greige_in_stock_oracle', 'qty_issued', 'wip', 'finished', 'dispatch', 'loss_qty', 'status_oracle', 'state', 'oracle_line_id'] as $field) {
        expect($after->getAttribute($field))->toEqual($before->getAttribute($field));
    }
});

it('ignores the same forbidden fields when a POST spoofs PUT with _method', function () {
    $before = $this->open->fresh();
    $payload = ['_method' => 'PUT', 'remarks' => 'spoofed'] + array_fill_keys(Lot::ORACLE_FIELDS, 1) + [
        'lot_no_oracle' => '999999', 'po_type_oracle' => 'PRINTED', 'greige_received_oracle' => 99, 'design' => 'HACKED', 'contract_id' => 99999,
        'greige_in_stock_oracle' => 99, 'qty_issued' => 99, 'wip' => 99, 'finished' => 99, 'dispatch' => 99,
        'loss_qty' => 99, 'status_oracle' => 'HACKED/', 'state' => 'cancelled', 'oracle_line_id' => 42,
    ];
    $this->actingAs($this->ppc)->post("/production/lots/{$this->open->id}", $payload)->assertSessionHasNoErrors();

    $after = $this->open->fresh();
    expect($after->remarks)->toBe('spoofed')->and($after->clr_status_oracle)->toBe('DIP');
    foreach ([...Lot::ORACLE_FIELDS, 'greige_in_stock_oracle', 'qty_issued', 'wip', 'finished', 'dispatch', 'loss_qty', 'status_oracle', 'state', 'oracle_line_id'] as $field) {
        expect($after->getAttribute($field))->toEqual($before->getAttribute($field));
    }
});

it('refuses a dotted key and rejects an array value on either editable field', function () {
    // `clr_status_oracle.x` is not `clr_status_oracle`: nothing the user may write was sent, so it is a 403, not a write
    $this->actingAs($this->ppc)->put("/production/lots/{$this->open->id}", ['clr_status_oracle.x' => 'APPROVED', 'remarks.y' => 'z'])
        ->assertForbidden();
    $this->actingAs($this->ppc)->putJson("/production/lots/{$this->open->id}", ['clr_status_oracle' => ['APPROVED'], 'remarks' => ['z']])
        ->assertStatus(422)->assertJsonValidationErrors(['clr_status_oracle', 'remarks']);

    $lot = $this->open->fresh();
    expect($lot->clr_status_oracle)->toBe('DIP')->and($lot->remarks)->toBeNull();
});

it('lets the colour lab set only the colour status and ignores the remarks it may not write', function () {
    $this->actingAs($this->colourLab)->put("/production/lots/{$this->open->id}", ['clr_status_oracle' => 'APPROVED', 'remarks' => 'nope'])
        ->assertSessionHasNoErrors();

    $lot = $this->open->fresh();
    expect($lot->clr_status_oracle)->toBe('APPROVED')->and($lot->remarks)->toBeNull();
});

it('forbids a user with neither permission from saving anything', function () {
    $this->actingAs($this->viewer)->put("/production/lots/{$this->open->id}", ['remarks' => 'x'])->assertForbidden();
    $this->actingAs($this->deptIncharge)->put("/production/lots/{$this->open->id}", ['clr_status_oracle' => 'APPROVED'])->assertForbidden();
    expect($this->open->fresh()->remarks)->toBeNull();
});

it('forbids a save that carries no field the user may write', function () {
    $this->actingAs($this->colourLab)->put("/production/lots/{$this->open->id}", ['remarks' => 'x'])->assertForbidden();
});

// ---- access ---------------------------------------------------------------------------------------------------

it('opens for every seeded role (all have inquiry.view) and 404s on an unknown lot', function () {
    foreach (['admin', 'ppc', 'manager', 'dept_incharge', 'colour_lab', 'dispatch', 'viewer'] as $role) {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user)->get('/production/lots')->assertOk();
    }
    $this->actingAs($this->ppc)->put('/production/lots/999999', ['remarks' => 'x'])->assertNotFound();
});

it('redirects a guest to the login page', function () {
    $this->get('/production/lots')->assertRedirect('/login');
});

it('reports the permission flags the screen enables its fields with', function () {
    $this->actingAs($this->ppc)->get('/production/lots')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('can.colour', true)->where('can.remarks', true)->where('can.hold', true));
    $this->actingAs($this->colourLab)->get('/production/lots')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('can.colour', true)->where('can.remarks', false)->where('can.hold', false));
});
