<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\ProductionAnalyticsService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/*
 * The flat, Oracle-shaped lot payload (`ProductionAnalyticsService::flatLots()`), the fabric report that
 * lists it under the stage -> stage process grouping, and the Lots screen's two grid shapes.
 *
 * Since the physical rename the payload is **no longer a projection**: `lots` carries the mill's own field
 * names, with the party, the PO and the quality denormalised onto the row, so twenty-three of the keys are
 * `lots.*` read under their own names. What is worth freezing is the contract the report and the grid read:
 *
 *  - the 25 keys, in the mill's own order (the first 21 are the user's list; the last four are additive,
 *    and two of those four — `stage_process_id` / `stage_process_name` — are always null now that
 *    resolution is stage-only, kept so the published shape does not change);
 *  - every key read from the column the mill means by it;
 *  - quantities as numbers, never the driver's `decimal` strings;
 *  - a null that stays null — an unmeasured width and an unresolved stage are absent, not `0` or `''`;
 *  - `date_oracle` as dd-mm-yyyy;
 *  - one query for a page of rows, however many rows the page holds;
 *  - the same query-mode criteria as the nested screen;
 *  - and, additively, the pre-existing `LotScreenRow` payload unchanged in either shape.
 */

/** The payload's keys, written out here on purpose: a reorder in the service must fail this test. */
const FLAT_KEYS_EXPECTED = [
    // the user's 21, in their order
    'party_oracle_id',
    'party_oracle_name',
    'po_no',
    'ref',
    'design_oracle_id',
    'color_oracle_id',
    'color_oracle_name',
    'clr_status_oracle',
    'po_type_oracle',
    'date_oracle',
    'lot_no_oracle',
    'quality',
    'gw_oracle',
    'status_oracle',
    'greige_received_oracle',
    'greige_in_stock_oracle',
    'qty_issued',
    'wip',
    'finished',
    'dispatch',
    'stage_id',
    // the four additive ones
    'stage_name',
    'stage_process_id',
    'stage_process_name',
    'lot_id',
];

/**
 * `LotController::row()` — the nested record, frozen key for key as the regression guard.
 *
 * Two changes from the pre-rename list, both forced by this change rather than chosen: every key that named
 * a renamed column now names the new one, and `stage_process_id` / `stage_process` are gone, because there
 * is no column and no relation left to produce them.
 */
const NESTED_KEYS_EXPECTED = [
    'id', 'lot_no_oracle', 'contract_id', 'po_no', 'party', 'ref_no', 'po_date', 'quality', 'gsm', 'design',
    'color_oracle_name', 'clr_status_oracle', 'gw_oracle', 'f_width', 'po_type_oracle', 'state', 'hold_reason',
    'cancel_reason', 'state_changed_at', 'status_oracle', 'process_flag', 'stage_id', 'stage',
    'stage_overridden', 'stage_overridden_at', 'stage_overridden_by', 'greige_received_oracle',
    'greige_in_stock_oracle', 'qty_issued', 'wip', 'finished', 'dispatch', 'loss_qty', 'last_movement_at',
    'oracle_missing_at', 'remarks',
];

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, StageSeeder::class]);
    $this->viewer = User::factory()->create()->assignRole('viewer');
    $this->analytics = app(ProductionAnalyticsService::class);

    // A lot with every Oracle field filled, sitting at a resolved stage and process.
    $this->party = Party::create(['name' => 'ALKARAM TEXTILE', 'type' => 'brand', 'oracle_code' => 7731]);
    $this->contract = Contract::factory()->create([
        'po_no' => 261071, 'po_date' => '2026-08-17', 'ref_no' => 'REF-1', 'party_id' => $this->party->id,
    ]);
    $this->dyed = Lot::factory()->create([
        'contract_id' => $this->contract->id, 'lot_no_oracle' => '264899',
        'design_oracle_id' => 'D-1143', 'design' => 'E1143/119',
        'color_oracle_id' => 'C520', 'color_oracle_name' => '520 PLUM', 'clr_status_oracle' => 'DIP',
        'po_type_oracle' => 'DYED', 'gw_oracle' => 63.5, 'greige_received_oracle' => 300,
    ]);
    $dyeing = Stage::query()->where('code', 'DYEING')->firstOrFail();
    // The derived columns are LedgerService's; a plain builder update keeps this test out of its way.
    DB::table('lots')->where('id', $this->dyed->id)->update([
        'greige_in_stock_oracle' => 10.5, 'qty_issued' => 20.25, 'wip' => 30, 'finished' => 40, 'dispatch' => 50,
        'status_oracle' => 'DYEING/', 'stage_id' => $dyeing->getKey(),
    ]);
    $this->dyeing = $dyeing;

    // The awkward lot: no party code, no PO ref, no design / colour / colour status, no greige width and
    // no stage at all. A left join keeps it; a coerced null would report an unmeasured width as a width of
    // zero.
    $this->bareParty = Party::create(['name' => 'UNMAPPED MILLS', 'type' => 'local', 'oracle_code' => null]);
    $this->bareContract = Contract::factory()->create([
        'po_no' => 261072, 'po_date' => '2026-09-01', 'ref_no' => null, 'party_id' => $this->bareParty->id,
    ]);
    $this->bare = Lot::factory()->create([
        'contract_id' => $this->bareContract->id, 'lot_no_oracle' => '255025',
        'design_oracle_id' => null, 'design' => null, 'color_oracle_id' => null, 'color_oracle_name' => null,
        'clr_status_oracle' => null, 'po_type_oracle' => 'HALF BLEACH', 'gw_oracle' => null, 'greige_received_oracle' => 200,
    ]);

    // PO date descending, then PO number, then lot number: the bare lot's PO is the newer one.
    $this->flat = fn (array $criteria = [], ?array $states = null, ?int $limit = null): array => $this->analytics
        ->flatLots($criteria, $states, $limit);
});

// ---- the payload's shape --------------------------------------------------------------------------------------

it('gives one flat row per lot, with the 25 keys in the mill\'s own order', function () {
    $rows = ($this->flat)();

    expect($rows)->toHaveCount(2)
        ->and(array_keys($rows[0]))->toBe(FLAT_KEYS_EXPECTED)
        ->and(array_keys($rows[1]))->toBe(FLAT_KEYS_EXPECTED)
        // the service's own constant must say the same thing, so the SQL projection cannot drift from it
        ->and(ProductionAnalyticsService::FLAT_KEYS)->toBe(FLAT_KEYS_EXPECTED);
});

it('reads every key from the column the mill means by it', function () {
    $row = collect(($this->flat)())->firstWhere('lot_id', $this->dyed->id);

    expect($row)->toBe([
        'party_oracle_id' => 7731,                                     // parties.oracle_code
        'party_oracle_name' => 'ALKARAM TEXTILE',                      // parties.name
        'po_no' => 261071,                                             // contracts.po_no
        'ref' => 'REF-1',                                              // contracts.ref_no
        'design_oracle_id' => 'D-1143',                                // lots.design_oracle_id
        'color_oracle_id' => 'C520',                                   // lots.color_oracle_id
        'color_oracle_name' => '520 PLUM',                             // lots.color_oracle_name
        'clr_status_oracle' => 'DIP',                                  // lots.clr_status_oracle
        'po_type_oracle' => 'DYED',                                    // lots.po_type_oracle
        'date_oracle' => '17-08-2026',                                 // lots.date_oracle, dd-mm-yyyy
        'lot_no_oracle' => '264899',                                   // lots.lot_no_oracle
        'quality' => $this->dyed->quality,                             // lots.quality
        'gw_oracle' => 63.5,                                           // lots.gw_oracle
        'status_oracle' => 'DYEING/',                                  // lots.status_oracle
        'greige_received_oracle' => 300.0,                             // lots.greige_received_oracle
        'greige_in_stock_oracle' => 10.5,                              // lots.greige_in_stock_oracle
        'qty_issued' => 20.25,                                         // lots.qty_issued
        'wip' => 30.0,                                                 // lots.wip
        'finished' => 40.0,                                            // lots.finished
        'dispatch' => 50.0,                                            // lots.dispatch
        'stage_id' => $this->dyeing->getKey(),                         // lots.stage_id
        'stage_name' => $this->dyeing->name,                           // stages.name
        'stage_process_id' => null,                                    // no column: resolution is stage-only
        'stage_process_name' => null,                                  // the same
        'lot_id' => $this->dyed->id,                                   // lots.id
    ]);
});

it('serialises the quantities as numbers, not the driver\'s decimal strings', function () {
    $row = collect(($this->flat)())->firstWhere('lot_id', $this->dyed->id);

    foreach ([
        'gw_oracle', 'greige_received_oracle', 'greige_in_stock_oracle', 'qty_issued', 'wip', 'finished', 'dispatch',
    ] as $key) {
        expect($row[$key])->toBeFloat("{$key} must be a number, not a decimal string");
    }
    expect($row['party_oracle_id'])->toBeInt()
        ->and($row['po_no'])->toBeInt()
        ->and($row['stage_id'])->toBeInt()
        ->and($row['lot_id'])->toBeInt();

    // ... and over the wire they are JSON numbers, never quoted. PHP writes a whole float without its `.0`
    // (`300`, not `300.0`), which JSON.parse still reads as a number; a `decimal` string would come out as
    // `"300.00"` and a grid would have to parse it before it could total or right-align it.
    $json = (string) json_encode($row);
    expect($json)->toContain('"greige_received_oracle":300,')
        ->and($json)->toContain('"greige_in_stock_oracle":10.5,')
        ->and($json)->toContain('"gw_oracle":63.5,')
        ->and($json)->not->toContain('"300.00"')
        ->and($json)->not->toContain('"10.50"')
        ->and($json)->not->toContain('"63.50"');
    foreach (['greige_received_oracle', 'greige_in_stock_oracle', 'qty_issued', 'wip', 'gw_oracle'] as $key) {
        expect(json_decode($json, true)[$key])->not->toBeString("{$key} must not cross the wire as a string");
    }
});

it('leaves a null null: an unmeasured width, an unresolved stage and a missing party code stay absent', function () {
    $row = collect(($this->flat)())->firstWhere('lot_id', $this->bare->id);

    // the left join kept the row at all — an inner join on stages would have dropped it
    expect($row)->not->toBeNull();
    foreach ([
        'party_oracle_id', 'ref', 'design_oracle_id', 'color_oracle_id', 'color_oracle_name',
        'clr_status_oracle', 'gw_oracle', 'status_oracle', 'stage_id', 'stage_name', 'stage_process_id',
        'stage_process_name',
    ] as $key) {
        expect($row[$key])->toBeNull("{$key} must stay null, never 0 or ''");
    }
    // ... and the things that are genuinely zero are zero, not null: "not measured" and "zero metres" differ
    expect($row['greige_received_oracle'])->toBe(200.0)
        ->and($row['greige_in_stock_oracle'])->toBe(0.0)
        ->and($row['wip'])->toBe(0.0)
        ->and($row['po_type_oracle'])->toBe('HALF BLEACH')
        ->and($row['lot_no_oracle'])->toBe('255025');
});

it('formats the PO date as dd-mm-yyyy', function () {
    expect(collect(($this->flat)())->pluck('date_oracle')->all())
        ->toBe(['01-09-2026', '17-08-2026']); // PO date descending
});

// ---- the cost ------------------------------------------------------------------------------------------------

it('costs one query for a page of rows, however many rows the page holds', function () {
    // enough rows that an accessor or a lazy relation would show up as a per-row query
    for ($i = 0; $i < 8; $i++) {
        Lot::factory()->create(['contract_id' => $this->contract->id, 'lot_no_oracle' => (string) (270000 + $i)]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $rows = ($this->flat)([], ['open', 'hold'], 200);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($rows)->toHaveCount(10)
        ->and($queries)->toHaveCount(1, 'the flat payload must cost exactly one query for the page');
});

// ---- query mode ----------------------------------------------------------------------------------------------

it('narrows the flat payload through the same query-mode criteria as the nested screen', function () {
    $ids = fn (array $criteria): array => collect(($this->flat)($criteria))->pluck('lot_id')->all();

    expect($ids(['po_no' => '261071']))->toBe([$this->dyed->id])
        ->and($ids(['party' => 'alkaram%']))->toBe([$this->dyed->id])
        ->and($ids(['stage' => $this->dyeing->name]))->toBe([$this->dyed->id])
        ->and($ids(['clr_status_oracle' => '#NULL']))->toBe([$this->bare->id])
        ->and($ids(['po_date' => '01-09-2026..30-09-2026']))->toBe([$this->bare->id])
        ->and($ids(['lot_no_oracle' => '']))->toHaveCount(2)                  // a blank box is no criterion
        ->and($ids(['no_such_field' => 'x']))->toHaveCount(2);         // an unknown field is ignored, not queried

    // ... and a criterion the parser rejects is a validation error here too, not a silent full table read
    expect(fn () => ($this->flat)(['po_no' => 'not-a-number']))->toThrow(ValidationException::class);
});

it('counts the same matches it lists', function () {
    expect($this->analytics->flatLotsCount([], ['open', 'hold']))->toBe(2)
        ->and($this->analytics->flatLotsCount(['po_no' => '261071']))->toBe(1)
        // the count is the true number of matches, not the capped page
        ->and(($this->flat)([], ['open', 'hold'], 1))->toHaveCount(1);
});

// ---- the fabric report ---------------------------------------------------------------------------------------

it('keeps the stage grouping, with each stage\'s own lots listed flat under it', function () {
    $report = $this->analytics->fabricReport(null);

    $stage = collect($report['stages'])->firstWhere('id', $this->dyeing->getKey());
    $unclassified = collect($report['stages'])->firstWhere('code', 'UNCLASSIFIED');

    expect($report['rows_total'])->toBe(2)
        // the grouping and its totals are untouched
        ->and($stage['lots'])->toBe(1)
        ->and($stage['wip'])->toBe(30.0)
        // the lots themselves, in the flat shape, under the stage that claims them
        ->and(array_column($stage['rows'], 'lot_id'))->toBe([$this->dyed->id])
        ->and(array_keys($stage['rows'][0]))->toBe(FLAT_KEYS_EXPECTED)
        // a lot no stage claims lands under the unclassified row, where its quantities already go
        ->and(array_column($unclassified['rows'], 'lot_id'))->toBe([$this->bare->id])
        ->and($unclassified['lots'])->toBe(1);
    // every row in the report appears exactly once across the whole grouping
    $listed = collect($report['stages'])->flatMap(fn (array $s): array => array_column($s['rows'], 'lot_id'))->all();
    expect($listed)->toHaveCount(2)->and(array_unique($listed))->toHaveCount(2);
});

it('serves the report as JSON, grouped by default and flat on request', function () {
    $this->actingAs($this->viewer)->getJson('/production/fabric-report')
        ->assertOk()
        ->assertJsonPath('shape', 'grouped')
        ->assertJsonPath('states', ['open', 'hold'])
        ->assertJsonPath('rows_total', null)
        ->assertJsonMissingPath('stages.0.rows');

    $flat = $this->actingAs($this->viewer)->getJson('/production/fabric-report?shape=flat&states=all')->assertOk();
    $flat->assertJsonPath('shape', 'flat')->assertJsonPath('rows_total', 2);

    // over the wire the quantities are still numbers and the keys still in order
    $rows = collect($flat->json('stages'))->flatMap(fn (array $s): array => $s['rows'])->all();
    $dyed = collect($rows)->firstWhere('lot_id', $this->dyed->id);
    expect($rows)->toHaveCount(2)
        ->and(array_keys($dyed))->toBe(FLAT_KEYS_EXPECTED)
        // numbers, not the driver's decimal strings (a whole float crosses as `300`, which is still a number)
        ->and($dyed['greige_received_oracle'])->not->toBeString()
        ->and($dyed['greige_received_oracle'])->toEqual(300)
        ->and($dyed['greige_in_stock_oracle'])->toBeFloat()
        ->and($dyed['gw_oracle'])->toBeFloat()
        ->and($dyed['date_oracle'])->toBe('17-08-2026')
        ->and(collect($rows)->firstWhere('lot_id', $this->bare->id)['gw_oracle'])->toBeNull();
});

it('rejects an unknown report shape and ignores an unknown state', function () {
    $this->actingAs($this->viewer)->getJson('/production/fabric-report?shape=sideways')
        ->assertStatus(422);
    // a typo in the state list falls back to the open work rather than emptying the report
    $this->actingAs($this->viewer)->getJson('/production/fabric-report?states=nonsense')
        ->assertOk()->assertJsonPath('states', ['open', 'hold']);
});

// ---- the Lots screen's two shapes ----------------------------------------------------------------------------

it('sends the flat payload to the Lots screen only for the flat shape', function () {
    $this->actingAs($this->viewer)->get('/production/lots')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('production/Lots')
            ->where('shape', 'nested')
            ->has('lots', 2)
            ->missing('flatLots'));

    $response = $this->actingAs($this->viewer)->get('/production/lots?shape=flat');
    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('shape', 'flat')->has('flatLots', 2));

    // the prop as it is handed to the page: the key order is part of the contract the grid reads
    $props = $response->viewData('page')['props'];
    expect(array_keys($props['flatLots'][0]))->toBe(FLAT_KEYS_EXPECTED);

    // An unknown shape is a 422 on **both** endpoints, not a silent fallback to the app's own columns: a
    // screen that quietly drew different columns than the URL asked for looks like the flat payload is
    // broken, and a bookmarked typo would never be noticed. (`FabricReportController` always did this.)
    $this->actingAs($this->viewer)->getJson('/production/lots?shape=sideways')->assertStatus(422)
        ->assertJsonValidationErrors('shape');
});

it('narrows both shapes with one set of criteria, and defaults both to the open work', function () {
    Lot::factory()->create(['contract_id' => $this->contract->id, 'lot_no_oracle' => '300001', 'state' => 'closed']);

    // the default query is the open work in either shape
    $this->actingAs($this->viewer)->get('/production/lots?shape=flat')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 2)->has('flatLots', 2)->where('lotsTotal', 2));

    // one real criterion drops the default and narrows both grids the same way
    $this->actingAs($this->viewer)->get('/production/lots?shape=flat&q[state]=closed')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 1)->has('flatLots', 1)
            ->where('flatLots.0.lot_no_oracle', '300001'));
});

// ---- the regression guard ------------------------------------------------------------------------------------

it('leaves the pre-existing nested Lots payload exactly as it was, in either shape', function () {
    $nested = $this->actingAs($this->viewer)->get('/production/lots')
        ->viewData('page')['props']['lots'];
    $withFlat = $this->actingAs($this->viewer)->get('/production/lots?shape=flat')
        ->viewData('page')['props']['lots'];

    expect(array_keys($nested[0]))->toBe(NESTED_KEYS_EXPECTED)
        // adding the flat shape must not have changed one byte of the record the form blocks read
        ->and($withFlat)->toBe($nested)
        ->and($nested[1]['id'])->toBe($this->dyed->id)
        ->and($nested[1]['lot_no_oracle'])->toBe('264899')
        ->and($nested[1]['po_no'])->toBe(261071)
        ->and($nested[1]['po_date'])->toBe('17-08-2026')
        ->and($nested[1]['gw_oracle'])->toBe(63.5)
        ->and($nested[1]['greige_received_oracle'])->toBe(300.0)
        ->and($nested[1]['greige_in_stock_oracle'])->toBe(10.5)
        ->and($nested[1]['status_oracle'])->toBe('DYEING/')
        ->and($nested[1]['stage_id'])->toBe($this->dyeing->getKey())
        // and the lot that has no stage is still on the screen, with its nulls intact
        ->and($nested[0]['stage_id'])->toBeNull()
        ->and($nested[0]['gw_oracle'])->toBeNull();
});

it('leaves the Lot Inquiry payload reading the same lot it always did', function () {
    $this->actingAs($this->viewer)->get('/inquiry/lots/'.$this->dyed->id)->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('inquiry/LotInquiry')
            ->where('lot.id', $this->dyed->id)
            ->where('lot.lot_no_oracle', '264899')
            ->where('lot.po_no', 261071)
            ->where('lot.color_oracle_name', '520 PLUM')
            ->where('lot.greige_received_oracle', 300));
});
