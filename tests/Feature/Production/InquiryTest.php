<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Shift;
use App\Modules\Production\Http\Controllers\InquiryController;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\ProductionLine;
use App\Modules\Production\Models\ProductionSheet;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Services\LotStateService;
use App\Modules\Production\Services\ProductionSheetService;
use Carbon\CarbonImmutable;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia;

/*
 * Lot Inquiry and PO Inquiry (spec §4.4 screens 3–4, §9 History tab): the page props per tab after a full
 * import → issue → process → reversal run through the real services, partial reloads, the PO totals, 404s and
 * the permission matrix (every seeded role has inquiry.view).
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    config(['audit.console' => true]); // tests run in the console; the History tab needs the audit rows
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->viewer = User::factory()->create()->assignRole('viewer');
    $this->partial = fn (string $only): array => ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'inquiry/LotInquiry', 'X-Inertia-Partial-Data' => $only];
    $this->ledger = app(LedgerService::class);
    $this->routes = app(LotRouteService::class);
    $this->sheets = app(ProductionSheetService::class);
    // HALF BLEACH route: SINGING (SINGEING) → SINGLE BLEACH (JIGGER_DYEING) → DRY → WHITE FINISH FOR SANFORIZING → SANFORIZE
    // a fixed lot number: the factory's random one made `/search?q=1` redirect whenever it happened to end in 1
    $this->lot = Lot::factory()->create([
        'lot_no' => '264899',
        'po_type' => 'HALF BLEACH', 'greige_qty' => 300, 'colour_status' => 'APPROVED', 'design' => 'E1143/119', 'colour' => '520 PLUM',
    ]);
    $this->actingAs($this->ppc);
    $this->routes->assign($this->lot);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 300);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100, ['reason' => 'first issue']);
    $this->op = fn (string $dept, string $name) => Operation::where('name', $name)->whereHas('department', fn ($q) => $q->where('code', $dept))->firstOrFail();
    $this->header = fn (string $dept, string $machine, string $date = '2026-09-11') => [
        'sheet_date' => $date, 'shift_id' => Shift::where('code', 'A')->value('id'),
        'department_id' => Department::where('code', $dept)->value('id'), 'machine_id' => Machine::where('code', $machine)->value('id'),
    ];
});

/** Process the issued 100 m through SINGING on a sheet, then edit the line (reversal + re-post) so the ledger has a reversal pair. */
function runProduction(): ProductionSheet
{
    $t = test();
    $line = ['lot_id' => $t->lot->id, 'operation_id' => ($t->op)('SINGEING', 'SINGING')->id, 'qty_in' => 100, 'qty_out' => 98];
    $sheet = $t->sheets->save(null, ($t->header)('SINGEING', 'SINGEING'), [$line], $t->ppc);
    $id = $sheet->lines->first()->id;

    return $t->sheets->save($sheet, ($t->header)('SINGEING', 'SINGEING'), [['id' => $id, 'qty_out' => 97] + $line], $t->ppc);
}

// ---- lot page ---------------------------------------------------------------------------------------------------

it('shows the summary, positions, route timeline and record position, with the heavy tabs left out', function () {
    Lot::factory()->create(['contract_id' => $this->lot->contract_id, 'lot_no' => '999999']);
    $first = $this->lot->routeSteps()->first();
    $this->get("/inquiry/lots/{$this->lot->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('inquiry/LotInquiry')
            ->where('lot.id', $this->lot->id)->where('lot.lot_no', $this->lot->lot_no)->where('lot.po_no', $this->lot->contract->po_no)
            ->where('lot.design', 'E1143/119')->where('lot.colour', '520 PLUM')->where('lot.colour_status', 'APPROVED')
            ->where('lot.state', 'open')->where('lot.current_label', 'SINGEING/')->where('lot.process_flag', 'pending')
            ->where('lot.greige_qty', 300)->where('lot.stock_qty', 200)->where('lot.issued_qty', 100)->where('lot.wip_qty', 100)
            ->where('lot.finished_qty', 0)->where('lot.dispatched_qty', 0)->where('lot.loss_qty', 0)
            ->where('siblings.index', 1)->where('siblings.count', 2)->where('siblings.prev_id', null)->whereNot('siblings.next_id', null)
            ->has('positions', 2, fn (AssertableInertia $p) => $p->where('position', 'STOCK')->where('kind', 'stock')->where('qty', 200)->etc())
            ->has('route', 5, fn (AssertableInertia $p) => $p
                ->where('id', $first->id)->where('seq', 10)->where('operation', 'SINGING')->where('status', 'pending')
                ->where('from_position', 'ISSUED')->where('available', 100)->where('to_position', "STEP:{$first->id}")->where('at_position', 0)
                ->where('last_done_at', null)->where('is_added', false)->etc())
            ->where('tab', 'route')->missing('movements')->missing('lines')->missing('audit')
            ->has('holdReasons', 4)->has('colourStatuses')->where('canHold', true));
});

it('lists the movement history after an import, issue, process and reversal run, with reversal links and sources', function () {
    $sheet = runProduction();
    $line = $sheet->lines->first();
    $first = $this->lot->routeSteps()->first();
    expect(LotMovement::query()->where('lot_id', $this->lot->id)->count())->toBe(5); // import, issue, process, its reversal, the re-post

    $res = $this->get("/inquiry/lots/{$this->lot->id}?tab=movements")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('inquiry/LotInquiry')
            ->where('tab', 'movements')->missing('lines')->missing('audit')
            ->where('movements.total', 5)->has('movements.rows', 5)
            ->where('lot.stock_qty', 200)->where('lot.wip_qty', 97)
            ->has('route.0', fn (AssertableInertia $s) => $s->where('status', 'partial') /* 200 m still in STOCK upstream */ ->where('at_position', 97)->whereNot('last_done_at', null)->etc()));
    $rows = collect($res->viewData('page')['props']['movements']['rows']);

    // latest first: moved_at (the sheet date for production, the posting time otherwise), then id
    $key = fn (array $r): array => [CarbonImmutable::createFromFormat('d-m-Y H:i', $r['moved_at'])->getTimestamp(), $r['id']];
    $order = $rows->map($key)->all();
    $sorted = $order;
    usort($sorted, fn (array $a, array $b) => $b <=> $a);
    expect($order)->toBe($sorted)->and($rows->last()['moved_at'])->toBe('11-09-2026 12:00'); // the sheet-dated rows come after today's postings

    $process = $rows->where('kind', 'process')->sortBy('id')->values();
    expect($process)->toHaveCount(3);
    [$original, $reversal, $repost] = [$process[0], $process[1], $process[2]];
    // the original line: ISSUED → STEP:<first>, 100 in / 98 out, reversed when the line was edited
    expect($original)->toMatchArray(['from' => 'ISSUED', 'to' => "STEP:{$first->id}", 'qty_out' => 100, 'qty_in' => 98, 'user' => $this->ppc->name,
        'reversal_of_id' => null, 'reversed_by_id' => $reversal['id'], 'source_type' => 'line', 'source_id' => $line->id, 'sheet_id' => $sheet->id, 'import_id' => null,
        'moved_at' => '11-09-2026 12:00']);
    // the reversal: from/to and the quantities swapped, links back to the original, keeps the line as its source
    expect($reversal)->toMatchArray(['from' => "STEP:{$first->id}", 'to' => 'ISSUED', 'qty_out' => 98, 'qty_in' => 100,
        'reversal_of_id' => $original['id'], 'reversed_by_id' => null, 'reason' => __('production.reasons.line_edited'), 'source_type' => 'line', 'sheet_id' => $sheet->id]);
    // the re-post with the corrected output
    expect($repost)->toMatchArray(['from' => 'ISSUED', 'to' => "STEP:{$first->id}", 'qty_out' => 100, 'qty_in' => 97, 'reversal_of_id' => null, 'reversed_by_id' => null]);
    expect($rows->firstWhere('kind', 'issue'))->toMatchArray(['from' => 'STOCK', 'to' => 'ISSUED', 'qty_out' => 100, 'qty_in' => 100, 'reason' => 'first issue', 'source_type' => null, 'sheet_id' => null]);
    expect($rows->firstWhere('kind', 'import'))->toMatchArray(['from' => null, 'to' => 'STOCK', 'qty_out' => 0, 'qty_in' => 300]);
});

it('lists the production lines with their sheet, and the audit history of the lot, its steps and lines', function () {
    $sheet = runProduction();
    app(LotStateService::class)->hold($this->lot, 'PARTY_HOLD', $this->ppc);

    $this->get("/inquiry/lots/{$this->lot->id}?tab=lines")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tab', 'lines')->missing('movements')->missing('audit')
            ->where('lines.total', 1)->has('lines.rows', 1, fn (AssertableInertia $l) => $l
            ->where('sheet_id', $sheet->id)->where('date', '11-09-2026')->where('shift', 'A')->where('operation', 'SINGING')
            ->where('step_seq', 10)->where('is_reprocess', false)->where('qty_in', 100)->where('qty_out', 97)->where('colour_override', null)->etc()));

    $this->get("/inquiry/lots/{$this->lot->id}?tab=audit")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tab', 'audit')->missing('movements')->missing('lines')
            ->has('audit.rows')
            ->has('audit.rows.0', fn (AssertableInertia $a) => $a // the hold is the latest audited change
                ->where('model', 'Lot')->where('record_id', $this->lot->id)->where('event', 'updated')->where('user', $this->ppc->name)
                ->where('old.state', 'open')->where('new.state', 'hold')->where('new.hold_reason', 'PARTY_HOLD')->whereNot('at', null)->etc()));
    $rows = $this->get("/inquiry/lots/{$this->lot->id}?tab=audit")->viewData('page')['props']['audit'];
    $models = collect($rows['rows'])->pluck('model')->unique()->values()->all();
    expect($rows['total'])->toBe(count($rows['rows']))
        ->and($models)->toContain('Lot')->toContain('LotRouteStep')->toContain('ProductionLine')
        ->and(collect($rows['rows'])->where('model', 'LotRouteStep')->count())->toBeGreaterThanOrEqual(5); // 5 steps created (+ status updates)
});

it('serves a tab on a partial reload and ignores an unknown tab', function () {
    $headers = ($this->partial)('movements,tab');
    $this->get("/inquiry/lots/{$this->lot->id}?tab=movements", $headers)
        ->assertOk()->assertJsonPath('props.tab', 'movements')->assertJsonPath('props.movements.total', 2)->assertJsonMissingPath('props.lot')->assertJsonMissingPath('props.audit');
    $this->get("/inquiry/lots/{$this->lot->id}?tab=nope")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tab', 'route')->missing('movements'));
});

it('caps a heavy tab at 200 rows and reports the total', function () {
    for ($i = 0; $i < 205; $i++) {
        $this->ledger->post($this->lot, 'adjustment', null, Position::stock(), 0, 1);
    }
    $this->get("/inquiry/lots/{$this->lot->id}?tab=movements")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('movements.total', 207)->has('movements.rows', InquiryController::ROW_CAP));
});

it('shows a held, cancelled and closed lot with its reasons and process flag', function () {
    app(LotStateService::class)->hold($this->lot, 'QUALITY_ISSUE', $this->ppc);
    $this->get("/inquiry/lots/{$this->lot->id}")->assertInertia(fn (AssertableInertia $p) => $p
        ->where('lot.state', 'hold')->where('lot.hold_reason', 'QUALITY_ISSUE')->where('lot.current_label', 'HOLD/')->whereNot('lot.state_changed_at', null));

    $stock = Lot::factory()->create(['greige_qty' => 10]);
    $this->ledger->post($stock, 'import', null, Position::stock(), 0, 10);
    app(LotStateService::class)->cancel($stock, 'OTHER', $this->ppc);
    $this->get("/inquiry/lots/{$stock->id}")->assertInertia(fn (AssertableInertia $p) => $p
        ->where('lot.state', 'cancelled')->where('lot.cancel_reason', 'OTHER')->where('lot.process_flag', 'complete')->where('canHold', true));
});

it('tells a viewer it cannot hold, and shows the buttons flag only with production.hold', function () {
    $this->actingAs($this->viewer)->get("/inquiry/lots/{$this->lot->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('canHold', false));
});

it('offers the sheet and import links only to a role that may open them', function () {
    $lab = User::factory()->create()->assignRole('colour_lab'); // neither production.sheet.view nor oracle.import.view
    $this->actingAs($lab)->get("/inquiry/lots/{$this->lot->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('canOpenSheet', false)->where('canOpenImport', false));

    $this->actingAs($this->ppc)->get("/inquiry/lots/{$this->lot->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('canOpenSheet', true)->where('canOpenImport', true));

    // the viewer may open a sheet but not an Oracle import
    $this->actingAs($this->viewer)->get("/inquiry/lots/{$this->lot->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('canOpenSheet', true)->where('canOpenImport', false));
});

it('keeps the History tab, and its rows, to the roles with audit.view', function () {
    app(LotStateService::class)->hold($this->lot, 'PARTY_HOLD', $this->ppc);

    // a viewer has no audit.view: no flag, no prop, and ?tab=audit falls back to the route tab
    $this->actingAs($this->viewer)->get("/inquiry/lots/{$this->lot->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('canAudit', false)->missing('audit'));
    $this->actingAs($this->viewer)->get("/inquiry/lots/{$this->lot->id}?tab=audit")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('tab', 'route')->where('canAudit', false)->missing('audit'));
    // a hand-crafted partial reload asking for the tab by name gets nothing either
    $this->actingAs($this->viewer)->get("/inquiry/lots/{$this->lot->id}?tab=audit", ($this->partial)('audit,tab'))
        ->assertOk()->assertJsonPath('props.tab', 'route')->assertJsonMissingPath('props.audit');

    foreach (['admin', 'ppc', 'manager'] as $role) {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user)->get("/inquiry/lots/{$this->lot->id}?tab=audit")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('tab', 'audit')->where('canAudit', true)
                ->has('audit.rows.0', fn (AssertableInertia $a) => $a->where('model', 'Lot')->where('event', 'updated')->etc()));
    }
    // every other seeded role is refused the tab
    foreach ([Permissions::ROLE_DEPT_INCHARGE, Permissions::ROLE_COLOUR_LAB, Permissions::ROLE_DISPATCH] as $role) {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user)->get("/inquiry/lots/{$this->lot->id}?tab=audit")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('canAudit', false)->missing('audit'));
    }
});

it('drops a deleted sheet line from the History tab but keeps its posting and reversal in the Movements tab', function () {
    $sheet = runProduction();
    $lineId = $sheet->lines->first()->id;
    $this->get("/inquiry/lots/{$this->lot->id}?tab=audit")
        ->assertInertia(fn (AssertableInertia $p) => $p->has('audit.rows')); // the line is audited while it lives
    expect(collect($this->get("/inquiry/lots/{$this->lot->id}?tab=audit")->viewData('page')['props']['audit']['rows'])
        ->where('model', 'ProductionLine')->count())->toBeGreaterThan(0);

    $this->sheets->delete($sheet, $this->ppc);
    expect(ProductionLine::query()->whereKey($lineId)->exists())->toBeFalse();

    // the History tab matches by live id, so the line's rows are gone with it (the documented limitation)
    $audit = $this->get("/inquiry/lots/{$this->lot->id}?tab=audit")->assertOk()->viewData('page')['props']['audit'];
    expect(collect($audit['rows'])->where('model', 'ProductionLine')->count())->toBe(0);

    // the Movements tab is the surviving trail: the process postings and their reversals both stand
    $rows = collect($this->get("/inquiry/lots/{$this->lot->id}?tab=movements")->assertOk()
        ->viewData('page')['props']['movements']['rows'])->where('kind', 'process')->sortBy('id')->values();
    $reversals = $rows->filter(fn (array $r): bool => $r['reversal_of_id'] !== null);
    expect($rows)->toHaveCount(4) // the original, its edit reversal, the re-post, and the delete's reversal
        ->and($reversals)->toHaveCount(2)
        // the source line is gone, so the rows no longer carry a sheet link, but the ledger pair stands
        ->and($rows->last())->toMatchArray(['reason' => __('production.reasons.line_edited'), 'sheet_id' => null, 'source_type' => 'line'])
        ->and($rows->last()['reversal_of_id'])->not->toBeNull();
});

it('shows an audit row whose acting user has since been deleted', function () {
    $actor = User::factory()->create()->assignRole('ppc');
    app(LotStateService::class)->hold($this->lot, 'PARTY_HOLD', $actor);
    $actor->delete();

    $manager = User::factory()->create()->assignRole('manager');
    $this->actingAs($manager)->get("/inquiry/lots/{$this->lot->id}?tab=audit")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('canAudit', true)
            ->has('audit.rows.0', fn (AssertableInertia $a) => $a
                ->where('model', 'Lot')->where('event', 'updated')->where('user', null)->whereNot('at', null)->etc()));
});

// ---- PO page ----------------------------------------------------------------------------------------------------

it('shows the PO with its lots, their position summary and the totals', function () {
    $other = Lot::factory()->create(['contract_id' => $this->lot->contract_id, 'greige_qty' => 50, 'lot_no' => '100001']);
    $this->ledger->post($other, 'import', null, Position::stock(), 0, 50);
    $contract = $this->lot->contract;
    $this->get("/inquiry/contracts/{$contract->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('inquiry/PoInquiry')
            ->where('contract.id', $contract->id)->where('contract.po_no', $contract->po_no)->where('contract.party', $contract->party->name)
            ->where('contract.po_date', $contract->po_date->format('d-m-Y'))->where('contract.contract_qty', 1000)->where('contract.lots_count', 2)
            ->has('lots', 2, fn (AssertableInertia $l) => $l // lot_no order: 100001 first
                ->where('id', $other->id)->where('lot_no', '100001')->where('greige_qty', 50)->where('stock_qty', 50)->where('wip_qty', 0)
                ->where('state', 'open')->where('current_label', null)->where('process_flag', 'pending')->etc())
            ->has('lots.1', fn (AssertableInertia $l) => $l->where('id', $this->lot->id)->where('wip_qty', 100)->where('current_label', 'SINGEING/')->etc())
            ->where('lotsTotal', 2)
            ->where('totals.greige_qty', 350)->where('totals.stock_qty', 250)->where('totals.issued_qty', 100)->where('totals.wip_qty', 100)
            ->where('totals.finished_qty', 0)->where('totals.dispatched_qty', 0)->where('totals.loss_qty', 0));
});

it('caps the PO lots at 200, reports the true total and still totals every lot of the PO', function () {
    $contract = $this->lot->contract;
    for ($i = 0; $i < 205; $i++) {
        Lot::factory()->create(['contract_id' => $contract->id, 'greige_qty' => 2, 'lot_no' => (string) (200000 + $i)]);
    }
    $this->get("/inquiry/contracts/{$contract->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('lots', InquiryController::ROW_CAP)->where('lotsTotal', 206)->where('contract.lots_count', 206)
            // 205 x 2 m greige plus this->lot's 300 m: the totals row is the whole PO, not the page
            ->where('totals.greige_qty', 710)->where('totals.stock_qty', 200)->where('totals.issued_qty', 100)->etc());
});

// ---- 404s and permissions ---------------------------------------------------------------------------------------

it('returns 404 for an unknown or malformed id', function () {
    $this->get('/inquiry/lots/999999')->assertNotFound();
    $this->get('/inquiry/lots/abc')->assertNotFound();
    $this->get('/inquiry/contracts/999999')->assertNotFound();
    $this->get('/inquiry/contracts/abc')->assertNotFound();
});

it('lets every seeded role in, refuses a user without inquiry.view, and sends a guest to login', function () {
    foreach (array_keys(Permissions::ROLES) as $role) {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user)->get("/inquiry/lots/{$this->lot->id}")->assertOk();
        $this->actingAs($user)->get("/inquiry/contracts/{$this->lot->contract_id}")->assertOk();
        $this->actingAs($user)->get('/search?q=1')->assertOk();
    }
    $none = User::factory()->create();
    $this->actingAs($none)->get("/inquiry/lots/{$this->lot->id}")->assertForbidden();
    $this->actingAs($none)->get("/inquiry/contracts/{$this->lot->contract_id}")->assertForbidden();
    $this->actingAs($none)->get('/search?q=1')->assertForbidden();

    auth()->logout();
    $this->get("/inquiry/lots/{$this->lot->id}")->assertRedirect('/login');
    $this->get("/inquiry/contracts/{$this->lot->contract_id}")->assertRedirect('/login');
    $this->get('/search?q=1')->assertRedirect('/login');
});
