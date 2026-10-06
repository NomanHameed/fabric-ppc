<?php

use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\IssueService;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotIntakeService;
use App\Modules\Production\Services\MachineCapacityService;
use App\Modules\Production\Services\ProductionAnalyticsService;
use Carbon\CarbonImmutable;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole(Permissions::ROLE_PPC);
    $this->today = CarbonImmutable::today();
    $this->analytics = app(ProductionAnalyticsService::class);

    $this->lot = Lot::factory()->create(['greige_qty' => 1000, 'po_type' => 'DYED', 'created_at' => now()->subDays(10)]);
    app(LotIntakeService::class)->receive($this->lot, $this->ppc->id);
    $this->lot->refresh();
    $this->step = $this->lot->routeSteps()->firstOrFail();
    $this->department = $this->step->operation->department;
});

/** Push metres onto the first route step, which is what "produced" means to this service. */
function produce(int $qty, CarbonImmutable $on): void
{
    app(LedgerService::class)->post(
        test()->lot, 'process', Position::issued(), Position::step(test()->step->id), $qty, $qty,
        ['userId' => test()->ppc->id, 'movedAt' => $on->setTime(12, 0)],
    );
}

it('credits production to the department that owns the route step', function () {
    app(IssueService::class)->issue($this->lot, 1000, $this->ppc, $this->today->subDays(2));
    produce(400, $this->today->subDay());
    produce(250, $this->today);

    $data = $this->analytics->dashboard($this->today->subDays(3), $this->today)->toArray();

    $series = collect($data['by_department'])->firstWhere('id', $this->department->id);

    expect($series)->not->toBeNull()
        ->and($series['total'])->toBe(650.0)
        ->and($data['dates'])->toHaveCount(4)
        // day 0 and 1 are empty, then 400 and 250 — the axis keeps every day, so the shape is comparable.
        ->and($series['data'])->toBe([0.0, 0.0, 400.0, 250.0])
        ->and($data['kpi']['produced'])->toBe(650.0);
});

it('subtracts a reversal instead of counting it as a second day of production', function () {
    app(IssueService::class)->issue($this->lot, 1000, $this->ppc, $this->today->subDays(2));
    produce(400, $this->today->subDay());

    $movement = $this->lot->movements()->where('kind', 'process')->latest('id')->firstOrFail();
    app(LedgerService::class)->reverse($movement, $this->ppc->id, 'Keyed against the wrong lot');

    $data = $this->analytics->dashboard($this->today->subDays(3), $this->today)->toArray();

    expect($data['kpi']['produced'])->toBe(0.0);
});

it('counts issue, dispatch and loss on the flow series', function () {
    app(IssueService::class)->issue($this->lot, 800, $this->ppc, $this->today);
    produce(800, $this->today);
    $ledger = app(LedgerService::class);
    $ledger->post($this->lot, 'loss', Position::step($this->step->id), Position::loss(), 50, 50, ['movedAt' => $this->today->setTime(13, 0)]);
    $ledger->post($this->lot, 'process', Position::step($this->step->id), Position::finished(), 750, 750, ['movedAt' => $this->today->setTime(14, 0)]);
    $ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 750, 750, ['movedAt' => $this->today->setTime(15, 0)]);

    $flow = collect($this->analytics->dashboard($this->today, $this->today)->toArray()['flow'])->keyBy('key');

    expect($flow['issued']['total'])->toBe(800.0)
        ->and($flow['loss']['total'])->toBe(50.0)
        ->and($flow['dispatched']['total'])->toBe(750.0);
});

it('drops the lot-level flow lines when a department filter is on, rather than mixing scopes', function () {
    app(IssueService::class)->issue($this->lot, 800, $this->ppc, $this->today);
    produce(800, $this->today);

    $filtered = $this->analytics->dashboard($this->today, $this->today, $this->department)->toArray();

    expect(collect($filtered['flow'])->pluck('key')->all())->toBe(['produced'])
        ->and($filtered['by_department'])->toHaveCount(1)
        ->and($filtered['by_department'][0]['id'])->toBe($this->department->id);
});

it('narrows production to one process', function () {
    app(IssueService::class)->issue($this->lot, 800, $this->ppc, $this->today);
    produce(800, $this->today);

    $other = Operation::query()
        ->where('id', '!=', $this->step->operation_id)->firstOrFail();

    $matching = $this->analytics->dashboard($this->today, $this->today, null, $this->step->operation)->toArray();
    $wrong = $this->analytics->dashboard($this->today, $this->today, null, $other)->toArray();

    expect($matching['kpi']['produced'])->toBe(800.0)
        ->and($wrong['kpi']['produced'])->toBe(0.0);
});

it('reports where the metres are now, from the balances rather than the range', function () {
    app(IssueService::class)->issue($this->lot, 1000, $this->ppc, $this->today->subYears(2));
    produce(600, $this->today->subYears(2));

    // A range with no movements in it at all: the snapshot is still the truth about today.
    $stages = collect($this->analytics->dashboard($this->today, $this->today)->toArray()['wip_by_stage'])->keyBy('key');

    expect($stages['issued']['qty'])->toBe(400.0)
        ->and($stages['department:'.$this->department->id]['qty'])->toBe(600.0)
        ->and($stages['stock']['qty'])->toBe(0.0);
});

it('serves the dashboard over http and keeps capacity behind its own permission', function () {
    $machine = Machine::query()->where('code', 'STENTER-1')->firstOrFail();
    $machine->forceFill(['daily_capacity' => 1000, 'capacity_uom' => Machine::UOM_METRES])->save();
    app(MachineCapacityService::class)->allocate($machine, $this->today, 600);

    $response = $this->actingAs($this->ppc)
        ->getJson('/production/analytics?from='.$this->today->toDateString().'&to='.$this->today->toDateString())
        ->assertOk()
        ->assertJsonPath('range.from', $this->today->toDateString())
        ->assertJsonStructure(['range', 'dates', 'by_department', 'flow', 'wip_by_stage', 'kpi', 'filters']);

    // Machines come back ordered by code, so the row is found by code rather than by position.
    $stenter = collect($response->json('utilisation.machines'))->firstWhere('code', 'STENTER-1');
    // Cast: JSON gives back an int for a whole-metre figure, and the value is what matters here.
    expect((float) $stenter['capacity_qty'])->toBe(1000.0)->and((float) $stenter['allocated_qty'])->toBe(600.0);

    // The colour lab has inquiry.view but not production.sheet.view: charts yes, capacity no.
    $lab = User::factory()->create()->assignRole(Permissions::ROLE_COLOUR_LAB);
    $this->actingAs($lab)->getJson('/production/analytics')->assertOk()->assertJsonPath('utilisation', null);
});

it('refuses the dashboard to a role without inquiry.view', function () {
    $nobody = User::factory()->create();

    $this->actingAs($nobody)->getJson('/production/analytics')->assertForbidden();
});

it('clamps a range longer than a year instead of scanning it', function () {
    $from = $this->today->subYears(3);

    $data = $this->analytics->dashboard($from, $from->addDays(3))->toArray();
    expect($data['days'] ?? count($data['dates']))->toBe(4);

    $response = $this->actingAs($this->ppc)
        ->getJson('/production/analytics?from='.$from->toDateString().'&to='.$this->today->toDateString())
        ->assertOk();

    expect($response->json('range.to'))->toBe($from->addDays(366)->toDateString());
});

it('renders the timeline and capacity screens', function () {
    $this->actingAs($this->ppc)->get('/production/timeline?lot='.$this->lot->id)->assertOk()
        ->assertInertia(fn ($page) => $page->component('production/LotTimeline')
            ->where('lot.lot_no', $this->lot->lot_no)
            ->has('types'));

    $this->actingAs($this->ppc)->get('/production/timeline')->assertOk()
        ->assertInertia(fn ($page) => $page->where('lot', null));

    $this->actingAs($this->ppc)->get('/production/capacity')->assertOk()
        ->assertInertia(fn ($page) => $page->component('production/Utilisation')->has('departments'));
});

it('keeps the capacity screen behind production.sheet.view', function () {
    $lab = User::factory()->create()->assignRole(Permissions::ROLE_COLOUR_LAB);

    $this->actingAs($lab)->get('/production/capacity')->assertForbidden();
    $this->actingAs($lab)->get('/production/timeline')->assertOk();
});

it('lists every active department on the department utilisation, capacity or not', function () {
    $capacity = app(MachineCapacityService::class);
    $machine = Machine::query()->where('code', 'STENTER-1')->firstOrFail();
    $machine->forceFill(['daily_capacity' => 1000])->save();
    $stenter = Department::query()->where('code', 'FINISH_STENTER')->firstOrFail();
    $capacity->allocate($machine, $this->today, 600, department: $stenter);

    $rows = $capacity->byDepartment($this->today, $this->today)->keyBy('code');

    expect($rows['FINISH_STENTER']->allocatedQty)->toBe(600.0)
        ->and($rows['FINISH_STENTER']->capacityQty)->toBe(1000.0)
        ->and($rows['FINISH_STENTER']->percent())->toBe(60.0)
        ->and($rows->count())->toBe(Department::query()->where('is_active', true)->count());
});
