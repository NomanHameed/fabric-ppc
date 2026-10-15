<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Shift;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\ColourOverride;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\ProductionSheet;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Services\ProductionSheetService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * The sheet endpoints: validation, the policy per role and department (spec §8 "own dept"), the flashes, the JSON
 * the sheet screen consumes (lot context, batch split, totals), and the service's refusals arriving as field errors.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->manager = User::factory()->create()->assignRole('manager');
    $this->viewer = User::factory()->create()->assignRole('viewer');
    $this->singeing = Department::where('code', 'SINGEING')->firstOrFail();
    $this->drying = Department::where('code', 'DRYING')->firstOrFail();
    $this->incharge = User::factory()->create()->assignRole('dept_incharge');
    $this->incharge->departments()->attach($this->singeing->id);
    $this->ledger = app(LedgerService::class);
    $this->lot = Lot::factory()->create(['po_type_oracle' => 'HALF BLEACH', 'greige_received_oracle' => 100, 'clr_status_oracle' => 'APPROVED']);
    app(LotRouteService::class)->assign($this->lot);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $this->payload = fn (array $o = []) => $o + [
        'sheet_date' => '11-09-2026', 'shift_id' => Shift::where('code', 'A')->value('id'), 'department_id' => $this->singeing->id,
        'machine_id' => Machine::where('code', 'SINGEING')->value('id'), 'downtime_minutes' => 15, 'remarks' => null,
        'lines' => [['lot_id' => $this->lot->id, 'operation_id' => Operation::where('name', 'SINGING')->value('id'), 'qty_in' => 100, 'qty_out' => 99]],
    ];
    $this->dryingPayload = fn (array $o = []) => ($this->payload)($o + [
        'department_id' => $this->drying->id, 'machine_id' => Machine::where('code', 'DRYING-1')->value('id'), 'lines' => [],
    ]);
});

afterEach(function () {
    foreach (Lot::all() as $lot) {
        $this->ledger->assertInvariant($lot);
    }
});

// ---- store / update / destroy -----------------------------------------------------------------------------------

it('stores a sheet, flashes its id and posts its line', function () {
    $this->actingAs($this->ppc)->from('/production/sheets')->post('/production/sheets', ($this->payload)())->assertSessionHasNoErrors()->assertRedirect('/production/sheets');
    $sheet = ProductionSheet::firstOrFail();
    expect(ProductionSheet::count())->toBe(1)->and($sheet->sheet_date->format('Y-m-d'))->toBe('2026-09-11')->and($sheet->downtime_minutes)->toBe(15)
        ->and($sheet->entered_by)->toBe($this->ppc->id)->and($sheet->lines()->count())->toBe(1)
        ->and((float) $this->lot->fresh()->wip)->toBe(99.0)->and($this->ledger->balances($this->lot)['ISSUED'])->toBe(0.0);
});

it('validates the header and the lines', function () {
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)(['sheet_date' => '2026-09-11', 'shift_id' => 999, 'downtime_minutes' => 2000]))
        ->assertSessionHasErrors(['sheet_date', 'shift_id', 'downtime_minutes']);
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)(['lines' => [
        ['lot_id' => 999999, 'operation_id' => Operation::where('name', 'SINGING')->value('id'), 'qty_in' => 0, 'qty_out' => 1.234, 'output_type' => 'woven'],
    ]]))->assertSessionHasErrors(['lines.0.lot_id', 'lines.0.qty_in', 'lines.0.qty_out', 'lines.0.output_type']);
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)(['lines' => 'x']))->assertSessionHasErrors('lines');
    expect(ProductionSheet::count())->toBe(0);
});

it('refuses a sheet dated in the future and a machine that is no longer active', function () {
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)(['sheet_date' => now()->addDay()->format('d-m-Y')]))->assertSessionHasErrors('sheet_date');
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)(['sheet_date' => now()->format('d-m-Y')]))->assertSessionHasNoErrors(); // today is fine
    Machine::where('code', 'SINGEING')->update(['is_active' => false]);
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)(['sheet_date' => '10-09-2026']))->assertSessionHasErrors('machine_id');
    expect(ProductionSheet::count())->toBe(1);
});

it('rejects a second sheet for the same date, shift and machine', function () {
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)())->assertSessionHasNoErrors();
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)(['lines' => []]))->assertSessionHasErrors('machine_id');
    // the same sheet may keep its own key on update
    $sheet = ProductionSheet::firstOrFail();
    $this->actingAs($this->ppc)->put("/production/sheets/{$sheet->id}", ($this->payload)(['downtime_minutes' => 30, 'lines' => [
        ['id' => $sheet->lines()->value('id')] + ($this->payload)()['lines'][0],
    ]]))->assertSessionHasNoErrors();
    expect($sheet->fresh()->downtime_minutes)->toBe(30)->and(LotMovement::where('kind', 'process')->count())->toBe(1); // unchanged line: no re-post
});

it('updates a sheet through the service: a changed line is reversed and re-posted, a dropped line reversed', function () {
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)(['lines' => [
        ['lot_id' => $this->lot->id, 'operation_id' => Operation::where('name', 'SINGING')->value('id'), 'qty_in' => 60, 'qty_out' => 60],
        ['lot_id' => $this->lot->id, 'operation_id' => Operation::where('name', 'SINGING')->value('id'), 'qty_in' => 40, 'qty_out' => 40],
    ]]))->assertSessionHasNoErrors();
    $sheet = ProductionSheet::firstOrFail();
    [$a, $b] = $sheet->lines->all();
    $this->actingAs($this->ppc)->put("/production/sheets/{$sheet->id}", ($this->payload)(['lines' => [
        ['id' => $a->id, 'lot_id' => $this->lot->id, 'operation_id' => $a->operation_id, 'qty_in' => 60, 'qty_out' => 59],
    ]]))->assertSessionHasNoErrors()->assertRedirect();
    $s1 = $this->lot->routeSteps->first();
    expect($sheet->lines()->count())->toBe(1)->and((float) $a->fresh()->qty_out)->toBe(59.0)
        ->and(LotMovement::where('kind', 'process')->count())->toBe(5) // a, b, reversal of b, reversal of a, a again
        ->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 40.0, "STEP:{$s1->id}" => 59.0])
        ->and($s1->fresh()->status)->toBe('partial');
});

it('deletes a sheet and returns the metres', function () {
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)())->assertSessionHasNoErrors();
    $sheet = ProductionSheet::firstOrFail();
    $this->actingAs($this->ppc)->delete("/production/sheets/{$sheet->id}")->assertSessionHasNoErrors()->assertRedirect();
    expect(ProductionSheet::count())->toBe(0)->and((float) $this->lot->fresh()->wip)->toBe(100.0)
        ->and($this->ledger->balances($this->lot)['ISSUED'])->toBe(100.0)->and($this->lot->routeSteps->first()->fresh()->status)->toBe('pending');
});

it('turns the service refusals into field errors, never 500s', function () {
    // insufficient metres, with the available figure; a user who may override is told so on the reason field
    $short = ['lines' => [['lot_id' => $this->lot->id, 'operation_id' => Operation::where('name', 'SINGING')->value('id'), 'qty_in' => 150, 'qty_out' => 150]]];
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)($short))->assertSessionHasErrors(['lines.0.qty_in', 'lines.0.override_reason']);
    expect(session('errors')->first('lines.0.qty_in'))->toContain('100.00')
        ->and(session('errors')->first('lines.0.override_reason'))->toBe(__('production.errors.override_available'));
    // a department in-charge (no production.override) gets the refusal without the hint
    $this->actingAs($this->incharge)->post('/production/sheets', ($this->payload)($short))->assertSessionHasErrors('lines.0.qty_in')->assertSessionDoesntHaveErrors('lines.0.override_reason');
    // override with reason: adjustment + movement
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)(['lines' => [
        ['lot_id' => $this->lot->id, 'operation_id' => Operation::where('name', 'SINGING')->value('id'), 'qty_in' => 150, 'qty_out' => 150, 'override_reason' => 'Oracle short'],
    ]]))->assertSessionHasNoErrors();
    expect(LotMovement::where('lot_id', $this->lot->id)->orderBy('id')->pluck('kind')->all())->toBe(['import', 'issue', 'adjustment', 'process']);
    // the colour gate
    $this->lot->update(['clr_status_oracle' => 'DIP']);
    $bleach = ['lot_id' => $this->lot->id, 'operation_id' => Operation::where('name', 'SINGLE BLEACH')->value('id'), 'qty_in' => 150, 'qty_out' => 150];
    $jigger = ['department_id' => Department::where('code', 'JIGGER_DYEING')->value('id'), 'machine_id' => Machine::where('code', 'JIGGER')->value('id')];
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)($jigger + ['lines' => [$bleach]]))->assertSessionHasErrors('lines.0.colour_override_reason');
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)($jigger + ['lines' => [$bleach + ['colour_override_reason' => 'Verbal OK']]]))->assertSessionHasNoErrors();
    expect(ColourOverride::count())->toBe(1)->and(ProductionSheet::count())->toBe(2)->and($this->ledger->balances($this->lot)['STEP:'.$this->lot->routeSteps[1]->id])->toBe(150.0);
    // a held lot
    $this->lot->update(['state' => 'hold']);
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)(['sheet_date' => '12-09-2026']))->assertSessionHasErrors('lines.0.lot_id');
});

it('rejects a duplicate sheet key that slipped past validation as a machine_id error', function () {
    // two requests validated against the same snapshot: the second insert hits the unique key and is refused, not a 500
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)())->assertSessionHasNoErrors();
    $header = ['sheet_date' => '2026-09-11', 'shift_id' => Shift::where('code', 'A')->value('id'), 'department_id' => $this->singeing->id, 'machine_id' => Machine::where('code', 'SINGEING')->value('id')];
    try {
        app(ProductionSheetService::class)->save(null, $header, [], $this->ppc);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('machine_id');
    }
    expect(ProductionSheet::count())->toBe(1);
});

// ---- policy (spec §8) --------------------------------------------------------------------------------------------

it('lets a department in-charge enter, edit and delete only in their own department', function () {
    $this->actingAs($this->incharge)->post('/production/sheets', ($this->payload)())->assertSessionHasNoErrors();
    $this->actingAs($this->incharge)->post('/production/sheets', ($this->dryingPayload)())->assertForbidden();
    expect(ProductionSheet::count())->toBe(1);

    $own = ProductionSheet::firstOrFail();
    $this->actingAs($this->incharge)->put("/production/sheets/{$own->id}", ($this->payload)(['downtime_minutes' => 5, 'lines' => [['id' => $own->lines()->value('id')] + ($this->payload)()['lines'][0]]]))->assertSessionHasNoErrors();
    // moving their own sheet to another department needs rights there too
    $this->actingAs($this->incharge)->put("/production/sheets/{$own->id}", ($this->dryingPayload)())->assertForbidden();

    $foreign = ProductionSheet::factory()->create(['department_id' => $this->drying->id, 'machine_id' => Machine::where('code', 'DRYING-1')->value('id'), 'shift_id' => $own->shift_id]);
    $this->actingAs($this->incharge)->put("/production/sheets/{$foreign->id}", ($this->dryingPayload)(['sheet_date' => '12-09-2026']))->assertForbidden();
    $this->actingAs($this->incharge)->delete("/production/sheets/{$foreign->id}")->assertForbidden();
    $this->actingAs($this->incharge)->getJson("/production/sheets/{$foreign->id}/totals")->assertOk(); // viewing is not department-bound
    $this->actingAs($this->incharge)->delete("/production/sheets/{$own->id}")->assertSessionHasNoErrors();
    expect(ProductionSheet::count())->toBe(1);
});

it('lets edit.all roles enter every department, and a viewer none', function () {
    $this->actingAs($this->manager)->post('/production/sheets', ($this->dryingPayload)())->assertSessionHasNoErrors();
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->dryingPayload)(['sheet_date' => '12-09-2026']))->assertSessionHasNoErrors();
    $sheet = ProductionSheet::firstOrFail();
    $this->actingAs($this->viewer)->post('/production/sheets', ($this->payload)())->assertForbidden();
    // the rights are checked before validation: a viewer gets 403, not the field errors, for a broken payload
    $this->actingAs($this->viewer)->post('/production/sheets', ($this->payload)(['shift_id' => 999, 'lines' => 'x']))->assertForbidden();
    $this->actingAs($this->incharge)->post('/production/sheets', ($this->dryingPayload)(['shift_id' => 999]))->assertForbidden();
    $this->actingAs($this->incharge)->post('/production/sheets', ($this->payload)(['department_id' => 'abc']))->assertSessionHasErrors('department_id'); // unparseable: validation says so
    $this->actingAs($this->viewer)->put("/production/sheets/{$sheet->id}", ($this->dryingPayload)())->assertForbidden();
    $this->actingAs($this->viewer)->delete("/production/sheets/{$sheet->id}")->assertForbidden();
    $this->actingAs($this->viewer)->getJson("/production/sheets/{$sheet->id}/totals")->assertOk();
    expect(ProductionSheet::count())->toBe(2);
});

it('keeps the whole sheet area behind production.sheet.view', function () {
    $this->post('/production/sheets', ($this->payload)())->assertRedirect('/login');
    $colourLab = User::factory()->create()->assignRole('colour_lab');
    $this->actingAs($colourLab)->getJson("/production/sheets/lot-context/{$this->lot->id}?department_id={$this->singeing->id}")->assertForbidden();
    $this->actingAs($colourLab)->postJson('/production/sheets/split-batch', ['total' => 10, 'available' => [1 => 5]])->assertForbidden();
    $this->actingAs($colourLab)->post('/production/sheets', ($this->payload)())->assertForbidden();
});

// ---- JSON for the screen ------------------------------------------------------------------------------------------

it('serves the lot context and the batch split as JSON', function () {
    // whole numbers arrive as JSON integers (json_encode drops a zero fraction); the screen reads them as numbers either way
    $s1 = $this->lot->routeSteps->first();
    $this->actingAs($this->ppc)->getJson("/production/sheets/lot-context/{$this->lot->id}?department_id={$this->singeing->id}")->assertOk()
        ->assertJsonPath('lot_id', $this->lot->id)->assertJsonPath('lot_no_oracle', $this->lot->lot_no_oracle)->assertJsonPath('po_no', $this->lot->contract->po_no)
        ->assertJsonPath('balances.ISSUED', 100)->assertJsonPath('clr_status_oracle', 'APPROVED')->assertJsonPath('colour_approved', true)
        ->assertJsonPath('default_step_id', $s1->id)->assertJsonPath('next_step_id', $s1->id)
        ->assertJsonPath('floor_position', 'ISSUED')->assertJsonPath('floor_available', 100)
        ->assertJsonCount(5, 'steps')
        ->assertJsonPath('steps.0.id', $s1->id)->assertJsonPath('steps.0.operation', 'SINGING')->assertJsonPath('steps.0.department_id', $this->singeing->id)
        ->assertJsonPath('steps.0.status', 'pending')->assertJsonPath('steps.0.from_position', 'ISSUED')->assertJsonPath('steps.0.to_position', "STEP:{$s1->id}")
        ->assertJsonPath('steps.0.available', 100)->assertJsonPath('steps.1.available', 0)->assertJsonPath('steps.4.to_position', 'FINISHED');
    // the route is read once (positions and availabilities come from the loaded steps and one balances read): about
    // eight queries for the lot on top of the auth and permission reads, not several per step
    DB::enableQueryLog();
    $this->actingAs($this->ppc)->getJson("/production/sheets/lot-context/{$this->lot->id}?department_id={$this->singeing->id}")->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(20);
    DB::disableQueryLog();
    // another department: no default step there, so the operation would be recorded as unplanned
    $this->actingAs($this->ppc)->getJson("/production/sheets/lot-context/{$this->lot->id}?department_id=".Department::where('code', 'PRINTING')->value('id'))->assertOk()
        ->assertJsonPath('default_step_id', null)->assertJsonPath('next_step_id', $s1->id);
    $this->actingAs($this->ppc)->getJson("/production/sheets/lot-context/{$this->lot->id}")->assertUnprocessable();
    $this->actingAs($this->ppc)->getJson('/production/sheets/lot-context/999999?department_id='.$this->singeing->id)->assertNotFound();

    $this->actingAs($this->ppc)->postJson('/production/sheets/split-batch', ['total' => 90, 'available' => ['1' => 100, '2' => 50]])->assertOk()
        ->assertJsonPath('allocations.1', 60)->assertJsonPath('allocations.2', 30);
    $this->actingAs($this->ppc)->postJson('/production/sheets/split-batch', ['total' => 100, 'available' => ['a' => 1, 'b' => 1, 'c' => 1]])->assertOk()
        ->assertJsonPath('allocations.a', 33.34)->assertJsonPath('allocations.b', 33.33);
    $this->actingAs($this->ppc)->postJson('/production/sheets/split-batch', ['total' => 0, 'available' => []])->assertUnprocessable()->assertJsonValidationErrors(['total', 'available']);
});

it('serves the sheet totals as JSON', function () {
    $this->actingAs($this->ppc)->post('/production/sheets', ($this->payload)())->assertSessionHasNoErrors();
    $sheet = ProductionSheet::firstOrFail();
    $this->actingAs($this->ppc)->getJson("/production/sheets/{$sheet->id}/totals")->assertOk()
        ->assertJsonPath('totals.all.fresh', 99)->assertJsonPath('totals.all.total', 99)->assertJsonPath('totals.all.reprocess', 0)
        ->assertJsonPath('totals.other.fresh', 99)->assertJsonPath('totals.dyed.total', 0);
});

it('a viewer can call lot-context but not save', function () {
    $this->actingAs($this->viewer)->getJson("/production/sheets/lot-context/{$this->lot->id}?department_id={$this->singeing->id}")->assertOk();
    $this->actingAs($this->viewer)->postJson('/production/sheets/split-batch', ['total' => 10, 'available' => [1 => 5]])->assertOk();
    $this->actingAs($this->viewer)->post('/production/sheets', ($this->payload)())->assertForbidden();
});
