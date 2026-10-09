<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Shift;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\ProductionSheet;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;

/*
 * The sheet screen's page props (GET production/sheets): the list in query mode, the selected sheet with its lines
 * and totals, the lookups the form needs, and the per-role edit flags the screen decides from.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->sheets = ProductionSheet::factory()->count(3)->sequence(
        ['sheet_date' => '2026-09-10'], ['sheet_date' => '2026-09-11'], ['sheet_date' => '2026-09-12'],
    )->create();
});

it('lists sheets newest first with the lookups for the form', function () {
    $this->actingAs($this->ppc)->get('/production/sheets')->assertOk()
        ->assertInertia(fn ($page) => $page->component('production/ProductionSheet')
            ->has('sheets', 3)->where('sheets.0.sheet_date', '12-09-2026')->where('sheets.2.sheet_date', '10-09-2026')
            ->where('sheets.0.can_edit', true)->where('sheets.0.lines_count', 0)->where('sheets.0.qty_out', 0)
            ->where('selected', null)
            ->has('shifts', 2)->where('shifts.0.code', 'A')->has('departments')->has('machines')->has('operations')->has('reprocessReasons')
            // the grid decides a picked operation's reprocess kind, stretch flag and default output type from these
            ->has('operations.0', fn ($op) => $op->hasAll(['value', 'label', 'department_id', 'kind', 'is_stretch', 'finish_type']))
            ->where('canEditAll', true)->where('canEditOwn', false)->where('ownDepartmentIds', [])->where('criteria', []));
    $stenter = Operation::where('name', 'STRETCH FOR DYE')->firstOrFail();
    $this->actingAs($this->ppc)->get('/production/sheets')->assertInertia(fn ($page) => $page
        ->where('operations', fn ($ops) => collect($ops)->firstWhere('value', $stenter->id) == [
            'value' => $stenter->id, 'label' => 'STRETCH FOR DYE', 'department_id' => $stenter->department_id, 'kind' => $stenter->kind,
            'is_stretch' => (bool) $stenter->is_stretch, 'finish_type' => $stenter->finish_type,
        ]));
});

it('filters by query criteria and selects a sheet with its lines and totals', function () {
    $lot = Lot::factory()->create(['po_type_oracle' => 'HALF BLEACH', 'greige_received_oracle' => 100, 'clr_status_oracle' => 'APPROVED']);
    app(LotRouteService::class)->assign($lot);
    $ledger = app(LedgerService::class);
    $ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $singeing = Department::where('code', 'SINGEING')->firstOrFail();
    $this->actingAs($this->ppc)->post('/production/sheets', [
        'sheet_date' => '11-09-2026', 'shift_id' => Shift::where('code', 'B')->value('id'), 'department_id' => $singeing->id,
        'machine_id' => Machine::where('code', 'SINGEING')->value('id'), 'downtime_minutes' => 5, 'remarks' => 'paper sheet 7',
        'lines' => [['batch_no' => 'B1', 'lot_id' => $lot->id, 'operation_id' => Operation::where('name', 'SINGING')->value('id'), 'qty_in' => 100, 'qty_out' => 99]],
    ])->assertSessionHasNoErrors();
    $sheet = ProductionSheet::where('remarks', 'paper sheet 7')->firstOrFail();

    $this->actingAs($this->ppc)->get("/production/sheets?q[sheet_date]=11-09-2026&q[shift]=b&sheet={$sheet->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->has('sheets', 1)->where('sheets.0.id', $sheet->id)->where('sheets.0.lines_count', 1)->where('sheets.0.qty_out', 99)
            ->where('criteria', ['sheet_date' => '11-09-2026', 'shift' => 'b'])
            ->where('selected.id', $sheet->id)->where('selected.sheet_date', '11-09-2026')->where('selected.department_id', $singeing->id)
            ->where('selected.downtime_minutes', 5)->where('selected.remarks', 'paper sheet 7')->where('selected.can_edit', true)
            ->has('selected.lines', 1)
            ->where('selected.lines.0.lot_id', $lot->id)->where('selected.lines.0.lot', "{$lot->lot_no_oracle} · {$lot->contract->po_no} · {$lot->design} · {$lot->colour}")
            ->where('selected.lines.0.batch_no', 'B1')->where('selected.lines.0.operation', 'SINGING')->where('selected.lines.0.qty_in', 100)
            ->where('selected.lines.0.qty_out', 99)->where('selected.lines.0.lot_route_step_id', $lot->routeSteps->first()->id)
            ->where('selected.lines.0.is_stretch', false)->where('selected.lines.0.colour_override', null)
            ->where('selected.totals.all.fresh', 99)->where('selected.totals.all.total', 99)->where('selected.totals.dyed.total', 0));
    // a date range, an unknown sheet id, and an invalid criterion
    $this->actingAs($this->ppc)->get('/production/sheets?q[sheet_date]=10-09-2026..11-09-2026')->assertOk()
        ->assertInertia(fn ($page) => $page->has('sheets', 3));
    $this->actingAs($this->ppc)->get('/production/sheets?sheet=999999')->assertOk()->assertInertia(fn ($page) => $page->where('selected', null));
    $this->actingAs($this->ppc)->from('/production/sheets')->get('/production/sheets?q[sheet_date]=not-a-date')->assertRedirect('/production/sheets')->assertSessionHasErrors('sheet_date');
});

it('marks the sheets a department in-charge cannot edit and lists their departments', function () {
    $incharge = User::factory()->create()->assignRole('dept_incharge');
    $incharge->departments()->attach($this->sheets[0]->department_id);
    $this->actingAs($incharge)->get("/production/sheets?sheet={$this->sheets[1]->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('canEditAll', false)->where('canEditOwn', true)->where('ownDepartmentIds', [$this->sheets[0]->department_id])
            ->where('sheets.2.can_edit', true)->where('sheets.1.can_edit', false)->where('selected.can_edit', false));
    // a viewer sees everything and may edit nothing
    $viewer = User::factory()->create()->assignRole('viewer');
    $this->actingAs($viewer)->get('/production/sheets')->assertOk()
        ->assertInertia(fn ($page) => $page->has('sheets', 3)->where('canEditAll', false)->where('canEditOwn', false)->where('ownDepartmentIds', [])->where('sheets.0.can_edit', false));
    // an administrator (Gate::before) edits everything
    $admin = User::factory()->create()->assignRole('admin');
    $this->actingAs($admin)->get("/production/sheets?sheet={$this->sheets[1]->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('canEditAll', true)->where('sheets.1.can_edit', true)->where('selected.can_edit', true));
});

it('keeps the page behind production.sheet.view', function () {
    $this->get('/production/sheets')->assertRedirect('/login');
    $this->actingAs(User::factory()->create()->assignRole('colour_lab'))->get('/production/sheets')->assertForbidden();
});
