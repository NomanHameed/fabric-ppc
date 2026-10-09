<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\Setting;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Production\Http\Controllers\FabricReportController;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\ProductionAnalyticsService;
use App\Modules\Production\Status\LotStatusLabeller;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * R1 — the Month Wise Fabric Report screen (spec §7.1, §4.3).
 *
 * What is worth freezing here is not the markup but the contract the screen draws from:
 *
 *  - the stage grouping, with each stage's lots listed flat beneath it;
 *  - two totals levels (stage, grand) as **numbers**, each the sum of the matches it covers — resolution is
 *    stage-only, so there is no stage-process level to total;
 *  - that the totals are summed over every match while the rows are capped, so the screen is always told
 *    when the report was truncated (`rowsTotal > rowCap`) — a stage subtotal with no rows beneath it is a
 *    capped report, not a bug, and only this prop can say so;
 *  - the parameters: Month (on the PO date), Process (Pending / Complete / All, default Pending), Party,
 *    PO Type, Stage and the Oracle design code, all through the one `QueryCriteria` map;
 *  - who may open it at all;
 *  - and that a lot no stage claims lands in the `unclassified` bucket exactly once.
 */

/** The page's props as they are handed to Vue — where the numbers' types can actually be asserted. */
function reportProps(TestResponse $response): array
{
    /** @var array<string, mixed> $props */
    $props = $response->viewData('page')['props'];

    return $props;
}

/** Every flat row the report listed, across the whole grouping. */
function listedRows(array $stages): array
{
    $rows = [];
    foreach ($stages as $stage) {
        foreach ($stage['rows'] as $row) {
            $rows[] = $row;
        }
    }

    return $rows;
}

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, StageSeeder::class]);

    $this->ppc = User::factory()->create()->assignRole('ppc');              // reports.all
    $this->viewer = User::factory()->create()->assignRole('viewer');       // reports.all
    $this->incharge = User::factory()->create()->assignRole('dept_incharge'); // reports.department only
    $this->colourLab = User::factory()->create()->assignRole('colour_lab');   // reports.colour only

    $this->analytics = app(ProductionAnalyticsService::class);
    $this->dyeing = Stage::query()->where('code', 'DYEING')->firstOrFail();
    $this->dispatch = Stage::query()->where('code', 'DISPATCH')->firstOrFail();

    // Three lots that cover the report's three group buckets and both Process values. The derived columns
    // are LedgerService's, so a plain builder update keeps these fixtures out of its way.
    $alkaram = Party::create(['name' => 'ALKARAM TEXTILE', 'type' => 'brand', 'oracle_code' => 7731]);
    $this->contract = Contract::factory()->create([
        'po_no' => 261071, 'po_date' => '2026-08-17', 'ref_no' => 'REF-1', 'party_id' => $alkaram->id,
    ]);
    // Pending: it had greige and still has metres in stock, in process and finished.
    $this->dyed = Lot::factory()->create([
        'contract_id' => $this->contract->id, 'lot_no_oracle' => '264899', 'po_type_oracle' => 'DYED',
        'design_oracle_id' => 'D-1143', 'design' => 'E1143/119', 'greige_received_oracle' => 300,
    ]);
    DB::table('lots')->where('id', $this->dyed->id)->update([
        'greige_in_stock_oracle' => 10.5, 'qty_issued' => 20.25, 'wip' => 30, 'finished' => 40, 'dispatch' => 50,
        'status_oracle' => 'DYEING/', 'stage_id' => $this->dyeing->getKey(),
    ]);

    // Complete: it had greige and has nothing left in stock, in process or finished — everything dispatched.
    $this->shippedContract = Contract::factory()->create([
        'po_no' => 261070, 'po_date' => '2026-07-05', 'ref_no' => 'REF-0', 'party_id' => $alkaram->id,
    ]);
    $this->shipped = Lot::factory()->create([
        'contract_id' => $this->shippedContract->id, 'lot_no_oracle' => '264000', 'po_type_oracle' => 'PRINT',
        'design_oracle_id' => 'D-9000', 'design' => 'E9000/1', 'greige_received_oracle' => 200,
    ]);
    DB::table('lots')->where('id', $this->shipped->id)->update([
        'dispatch' => 200, 'status_oracle' => 'DISPATCHED/',
        'stage_id' => $this->dispatch->getKey(),
    ]);

    // Pending, and left in the unclassified bucket on purpose: its stage column is null.
    $local = Party::create(['name' => 'UNMAPPED MILLS', 'type' => 'local', 'oracle_code' => null]);
    $this->bareContract = Contract::factory()->create([
        'po_no' => 261072, 'po_date' => '2026-09-01', 'ref_no' => null, 'party_id' => $local->id,
    ]);
    $this->bare = Lot::factory()->create([
        'contract_id' => $this->bareContract->id, 'lot_no_oracle' => '255025', 'po_type_oracle' => 'HALF BLEACH',
        'design_oracle_id' => null, 'design' => null, 'greige_received_oracle' => 100,
    ]);
    DB::table('lots')->where('id', $this->bare->id)->update(['greige_in_stock_oracle' => 100]);

    $this->open = fn (string $query = '?process=all') => $this->actingAs($this->ppc)
        ->get('/production/reports/fabric'.$query);
});

// ---- the page and its props ----------------------------------------------------------------------------------

it('renders the report screen grouped by stage, with each stage\'s lots beneath it', function () {
    $response = ($this->open)();

    $response->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
        ->component('production/FabricReport')
        ->has('stages')
        ->has('totals')
        ->has('months')
        ->where('rowCap', FabricReportController::ROW_CAP)
        ->where('rowsShown', 3)
        ->where('rowsTotal', 3)
        ->where('params.process', 'all')
        ->where('params.month', null)
    );

    $props = reportProps($response);
    $stage = collect($props['stages'])->firstWhere('id', $this->dyeing->getKey());

    expect($stage['name'])->toBe($this->dyeing->name)
        ->and($stage['lots'])->toBe(1)
        ->and(array_column($stage['rows'], 'lot_id'))->toBe([$this->dyed->id])
        // the rows are the flat, Oracle-shaped payload, under the mill's own field names
        ->and($stage['rows'][0]['lot_no_oracle'])->toBe('264899')
        ->and($stage['rows'][0]['date_oracle'])->toBe('17-08-2026')
        ->and($stage['rows'][0]['wip'])->toBe(30.0);
});

it('carries the Oracle Reports header block: company, run date/time and the user', function () {
    Setting::put('company_name', 'ALBEEN TEXTILE MILLS');

    $props = reportProps(($this->open)());

    expect($props['header']['company'])->toBe('ALBEEN TEXTILE MILLS')
        ->and($props['header']['user'])->toBe($this->ppc->name)
        // dd-mm-yyyy HH:mm, the mill's own format (HANDOFF §7)
        ->and($props['header']['run_at'])->toMatch('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}$/');
});

it('offers every month of the PO dates that carry lots, newest first, as Mmm-yy', function () {
    $props = reportProps(($this->open)());

    expect($props['months'])->toBe([
        ['value' => '2026-09', 'label' => 'Sep-26'],
        ['value' => '2026-08', 'label' => 'Aug-26'],
        ['value' => '2026-07', 'label' => 'Jul-26'],
    ]);
});

// ---- the three totals levels ---------------------------------------------------------------------------------

it('subtotals each stage and gives a grand total, all as numbers', function () {
    $props = reportProps(($this->open)());

    $stage = collect($props['stages'])->firstWhere('id', $this->dyeing->getKey());

    foreach (['greige_received_oracle', 'greige_in_stock_oracle', 'qty_issued', 'wip', 'finished', 'dispatch'] as $key) {
        expect($stage[$key])->toBeFloat("the stage subtotal {$key} must be a number")
            ->and($props['totals'][$key])->toBeFloat("the grand total {$key} must be a number");
    }
    expect($stage['lots'])->toBeInt()->and($props['totals']['lots'])->toBeInt();

    // the stage subtotal and the lot's own figures agree
    expect($stage['greige_received_oracle'])->toBe(300.0)
        ->and($stage['wip'])->toBe(30.0);

    // the grand total is the whole mill: 300 + 200 + 100 greige, and every other quantity likewise
    expect($props['totals'])->toBe([
        'lots' => 3,
        'greige_received_oracle' => 600.0,
        'greige_in_stock_oracle' => 110.5,
        'qty_issued' => 20.25,
        'wip' => 30.0,
        'finished' => 40.0,
        'dispatch' => 250.0,
    ]);
});

it('makes every total the sum of the rows beneath it while nothing is truncated', function () {
    $props = reportProps(($this->open)());

    $pairs = [
        'greige_received_oracle' => 'greige_received_oracle',
        'greige_in_stock_oracle' => 'greige_in_stock_oracle',
        'qty_issued' => 'qty_issued',
        'wip' => 'wip',
        'finished' => 'finished',
        'dispatch' => 'dispatch',
    ];

    expect($props['rowsTotal'])->toBe($props['rowsShown']); // nothing was hidden, so they must agree

    $grand = ['lots' => 0] + array_fill_keys(array_keys($pairs), 0.0);
    foreach ($props['stages'] as $stage) {
        $stageSum = ['lots' => count($stage['rows'])] + array_fill_keys(array_keys($pairs), 0.0);
        foreach ($stage['rows'] as $row) {
            foreach ($pairs as $total => $field) {
                $stageSum[$total] = round($stageSum[$total] + $row[$field], 2);
            }
        }
        foreach ($stageSum as $key => $value) {
            expect($stage[$key])->toBe($value, "stage {$stage['code']} / {$key}");
            $grand[$key] = is_int($value) ? $grand[$key] + $value : round($grand[$key] + $value, 2);
        }
    }
    foreach ($grand as $key => $value) {
        expect($props['totals'][$key])->toBe($value, "grand total {$key}");
    }
});

it('tells the screen when the global row cap hid rows the totals still count', function () {
    // Enough lots on the same PO to push the report past its cap. Bulk-inserted rather than factory-built:
    // the cap is 2000 rows and this test is about the cap, not about the factory.
    $cap = FabricReportController::ROW_CAP;
    $extra = [];
    for ($i = 0; $i < $cap + 1; $i++) {
        $extra[] = [
            'oracle_line_id' => 500000 + $i,
            'lot_no_oracle' => (string) (300000 + $i),
            'contract_id' => $this->contract->id,
            'quality_id' => $this->dyed->quality_id,
            'po_type_oracle' => 'DYED',
            'greige_received_oracle' => 1,
            'greige_in_stock_oracle' => 1,          // greige received and metres still in stock => pending
            'party_oracle_name' => $this->dyed->party_oracle_name,
            'po_no' => $this->dyed->po_no,
            'ref' => $this->dyed->ref,
            'date_oracle' => $this->dyed->date_oracle,
            'quality' => $this->dyed->quality,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    foreach (array_chunk($extra, 500) as $chunk) {
        DB::table('lots')->insert($chunk);
    }

    $props = reportProps(($this->open)());

    expect($props['rowsShown'])->toBe($cap)
        ->and($props['rowsTotal'])->toBe($cap + 4)
        // the one thing the screen must be able to say: more matched than was listed
        ->and($props['rowsTotal'])->toBeGreaterThan($props['rowCap'])
        ->and(listedRows($props['stages']))->toHaveCount($cap);

    // ... and the totals disagree with the rows on purpose: they are summed over every match
    expect($props['totals']['lots'])->toBe($cap + 4)
        ->and($props['totals']['greige_received_oracle'])->toBe(600.0 + ($cap + 1));

    // the dispatched lot's PO is the oldest, so the cap cut it: its stage prints a subtotal with no rows,
    // which is exactly the case the truncation notice exists to explain
    $dispatch = collect($props['stages'])->firstWhere('id', $this->dispatch->getKey());
    expect($dispatch['lots'])->toBe(1)
        ->and($dispatch['dispatch'])->toBe(200.0)
        ->and($dispatch['rows'])->toBe([]);
});

// ---- the parameters ------------------------------------------------------------------------------------------

it('filters by Month on the PO date', function () {
    $august = reportProps(($this->open)('?process=all&month=2026-08'));

    expect($august['rowsTotal'])->toBe(1)
        ->and(array_column(listedRows($august['stages']), 'lot_id'))->toBe([$this->dyed->id])
        ->and($august['totals']['greige_received_oracle'])->toBe(300.0)
        ->and($august['params']['month'])->toBe('2026-08');

    // September holds only the lot no stage claims
    $september = reportProps(($this->open)('?process=all&month=2026-09'));
    expect(array_column(listedRows($september['stages']), 'lot_id'))->toBe([$this->bare->id]);

    // and "All" (no month) holds every one of them
    expect(reportProps(($this->open)('?process=all'))['rowsTotal'])->toBe(3);

    // a month that is not a month is a validation error, not a report over everything
    $this->actingAs($this->ppc)->getJson('/production/reports/fabric?month=August')
        ->assertStatus(422)->assertJsonValidationErrors('month');
});

it('filters by Process, and opens on Pending', function () {
    // Pending: greige received and metres still in stock, in process or finished.
    $pending = reportProps($this->actingAs($this->ppc)->get('/production/reports/fabric'));
    expect($pending['params']['process'])->toBe('pending')
        ->and(array_column(listedRows($pending['stages']), 'lot_id'))
        ->toEqualCanonicalizing([$this->bare->id, $this->dyed->id])
        ->and($pending['totals']['lots'])->toBe(2);

    // Complete: nothing left in stock, in process or finished — the lot that shipped.
    $complete = reportProps(($this->open)('?process=complete'));
    expect(array_column(listedRows($complete['stages']), 'lot_id'))->toBe([$this->shipped->id])
        ->and($complete['totals']['dispatch'])->toBe(200.0)
        ->and($complete['totals']['lots'])->toBe(1);

    // All: both halves, and the two add back up to it.
    $all = reportProps(($this->open)());
    expect($all['totals']['lots'])->toBe(3)
        ->and($all['totals']['lots'])->toBe($pending['totals']['lots'] + $complete['totals']['lots']);

    // Complete agrees with the Pending/Complete badge the Lots screen shows for the same lots.
    $labeller = app(LotStatusLabeller::class);
    expect($labeller->processFlag($this->shipped->fresh()))->toBe('complete')
        ->and($labeller->processFlag($this->dyed->fresh()))->toBe('pending')
        ->and($labeller->processFlag($this->bare->fresh()))->toBe('pending');

    // a Process that is not one of the three is a validation error
    $this->actingAs($this->ppc)->getJson('/production/reports/fabric?process=sideways')
        ->assertStatus(422)->assertJsonValidationErrors('process');
});

it('filters by Party, PO Type and the stage this branch added', function () {
    $ids = fn (string $query): array => array_column(
        listedRows(reportProps(($this->open)($query))['stages']),
        'lot_id',
    );

    expect($ids('?process=all&q[party]=alkaram%'))->toEqualCanonicalizing([$this->dyed->id, $this->shipped->id])
        ->and($ids('?process=all&q[po_type_oracle]=DYED'))->toBe([$this->dyed->id])
        ->and($ids('?process=all&q[stage]='.rawurlencode($this->dyeing->name)))->toBe([$this->dyed->id])
        ->and($ids('?process=all&q[stage]='.rawurlencode($this->dispatch->name)))->toBe([$this->shipped->id]);

    // the criteria the server ran come back to the screen's query-mode boxes (an object, so an empty set
    // crosses the wire as `{}` rather than as `[]`)
    expect((array) reportProps(($this->open)('?process=all&q[po_type_oracle]=DYED'))['criteria'])
        ->toBe(['po_type_oracle' => 'DYED']);

    // the totals follow the same filter, so a filtered report never prints another filter's totals
    expect(reportProps(($this->open)('?process=all&q[po_type_oracle]=DYED'))['totals']['greige_received_oracle'])->toBe(300.0);

    // a criterion QueryCriteria rejects is a field error, not a silent read of every lot
    $this->actingAs($this->ppc)->getJson('/production/reports/fabric?q[po_no]=not-a-number')
        ->assertStatus(422)->assertJsonValidationErrors('po_no');
});

it('queries the Oracle design code, which is a different column from the design description', function () {
    // `design` is lots.design (the description); `design_oracle_id` is lots.design_oracle_id, which the flat payload
    // exposes as `design_oracle_id` and the report shows in its own column.
    expect(collect($this->analytics->flatLots(['design_oracle_id' => 'D-1143']))->pluck('lot_id')->all())
        ->toBe([$this->dyed->id])
        ->and(collect($this->analytics->flatLots(['design_oracle_id' => 'd-%']))->pluck('lot_id')->all())
        ->toEqualCanonicalizing([$this->dyed->id, $this->shipped->id])
        ->and(collect($this->analytics->flatLots(['design_oracle_id' => '#NULL']))->pluck('lot_id')->all())
        ->toBe([$this->bare->id])
        // the description is still its own criterion and still matches the description, not the code
        ->and(collect($this->analytics->flatLots(['design' => 'E1143/119']))->pluck('lot_id')->all())
        ->toBe([$this->dyed->id])
        ->and($this->analytics->flatLots(['design' => 'D-1143']))->toBe([])
        ->and(ProductionAnalyticsService::LOT_CRITERIA['design_oracle_id'])
        ->toBe(['column' => 'lots.design_oracle_id', 'type' => 'text']);

    // ... and it narrows the report screen, totals and all
    $props = reportProps(($this->open)('?process=all&q[design_oracle_id]=D-1143'));
    expect(array_column(listedRows($props['stages']), 'lot_id'))->toBe([$this->dyed->id])
        ->and($props['totals']['greige_received_oracle'])->toBe(300.0);

    // the Lots screen's flat grid reads the same criterion through the same map
    $this->actingAs($this->viewer)->get('/production/lots?shape=flat&q[design_oracle_id]=D-1143')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('flatLots', 1)
            ->where('flatLots.0.design_oracle_id', 'D-1143'));
});

// ---- the unclassified bucket ---------------------------------------------------------------------------------

it('puts a lot no stage claims in the unclassified bucket exactly once', function () {
    $props = reportProps(($this->open)());

    $unclassified = collect($props['stages'])->where('code', 'UNCLASSIFIED');
    expect($unclassified)->toHaveCount(1); // one bucket, not one per stage

    $bucket = $unclassified->first();
    expect($bucket['id'])->toBeNull()
        ->and($bucket['lots'])->toBe(1)
        ->and($bucket['greige_received_oracle'])->toBe(100.0)
        ->and(array_column($bucket['rows'], 'lot_id'))->toBe([$this->bare->id]);

    // and it is listed once across the whole report, not twice and not dropped
    $listed = array_column(listedRows($props['stages']), 'lot_id');
    expect($listed)->toHaveCount(3)
        ->and(array_count_values($listed)[$this->bare->id])->toBe(1);
});

// ---- the permission gate -------------------------------------------------------------------------------------

it('opens for a role holding reports.all and 403s for one holding only a narrower reports permission', function () {
    // a guest is sent to log in, not shown the report (asserted first: `actingAs` below is sticky)
    $this->get('/production/reports/fabric')->assertRedirect('/login');

    foreach ([$this->ppc, $this->viewer] as $allowed) {
        $this->actingAs($allowed)->get('/production/reports/fabric')->assertOk();
    }

    // `reports.department` and `reports.colour` are not `reports.all`: R1 is mill-wide, with no department
    // dimension to scope it by.
    $this->actingAs($this->incharge)->get('/production/reports/fabric')->assertForbidden();
    $this->actingAs($this->colourLab)->get('/production/reports/fabric')->assertForbidden();

    // an admin passes every gate (AppServiceProvider's Gate::before)
    $this->actingAs(User::factory()->create()->assignRole('admin'))
        ->get('/production/reports/fabric')->assertOk();
});

// ---- the shape parameter, on both endpoints ------------------------------------------------------------------

it('rejects an unknown ?shape= on both the Lots screen and the fabric report', function () {
    $this->actingAs($this->viewer)->getJson('/production/lots?shape=sideways')
        ->assertStatus(422)->assertJsonValidationErrors('shape');
    $this->actingAs($this->viewer)->getJson('/production/fabric-report?shape=sideways')
        ->assertStatus(422)->assertJsonValidationErrors('shape');

    // the shapes each endpoint does know still work
    $this->actingAs($this->viewer)->get('/production/lots?shape=flat')->assertOk();
    $this->actingAs($this->viewer)->getJson('/production/fabric-report?shape=flat')->assertOk();
});

it('narrows the JSON endpoint by the same parameters, and says how many matched', function () {
    $this->actingAs($this->viewer)
        ->getJson('/production/fabric-report?shape=flat&states=all&process=complete')
        ->assertOk()
        ->assertJsonPath('process', 'complete')
        ->assertJsonPath('rows_total', 1)
        ->assertJsonPath('rows_matched', 1)
        ->assertJsonPath('row_cap', FabricReportController::ROW_CAP)
        ->assertJsonPath('totals.dispatch', 200);

    // the grouped shape takes the same parameters and still carries no rows
    $this->actingAs($this->viewer)
        ->getJson('/production/fabric-report?states=all&month=2026-08')
        ->assertOk()
        ->assertJsonPath('rows_total', null)
        ->assertJsonMissingPath('stages.0.rows');
});
