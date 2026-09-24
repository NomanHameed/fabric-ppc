<?php

use App\Models\User;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Masters\Resources\MachineResource;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Production\Models\LotBalance;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\MachineAllocation;
use App\Modules\Production\Services\MachineCapacityService;
use Carbon\CarbonImmutable;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole(Permissions::ROLE_PPC);
    $this->capacity = app(MachineCapacityService::class);
    $this->department = Department::query()->where('code', 'FINISH_STENTER')->firstOrFail();
    $this->machine = Machine::query()->where('code', 'STENTER-1')->firstOrFail();
    $this->machine->forceFill(['daily_capacity' => 1000, 'capacity_uom' => Machine::UOM_METRES])->save();
    $this->today = CarbonImmutable::today();
});

it('reads the daily figure, or the hourly one over a 24-hour mill day', function () {
    expect($this->machine->dailyCapacityQty())->toBe(1000.0);

    $hourly = Machine::query()->where('code', 'SANFO')->firstOrFail();
    $hourly->forceFill(['hourly_capacity' => 50, 'daily_capacity' => null])->save();
    expect($hourly->dailyCapacityQty())->toBe(50.0 * Machine::HOURS_PER_DAY);

    $unknown = Machine::query()->where('code', 'JUMBO')->firstOrFail();
    expect($unknown->dailyCapacityQty())->toBeNull();
});

it('allocates 600 of a 1000 metre day and reports 60%', function () {
    $operation = Operation::query()->where('department_id', $this->department->id)->firstOrFail();

    $allocation = $this->capacity->allocate($this->machine, $this->today, 600, $operation, by: $this->ppc);

    expect($allocation->department_id)->toBe($this->department->id)
        ->and($allocation->uom)->toBe(Machine::UOM_METRES)
        ->and((float) $allocation->allocated_qty)->toBe(600.0)
        ->and($allocation->created_by)->toBe($this->ppc->id);

    $utilisation = $this->capacity->forMachine($this->machine, $this->today, $this->today);

    expect($utilisation->capacityQty)->toBe(1000.0)
        ->and($utilisation->allocatedQty)->toBe(600.0)
        ->and($utilisation->percent())->toBe(60.0)
        ->and($utilisation->freeQty())->toBe(400.0)
        ->and($utilisation->days)->toBe(1);
});

it('scales capacity by the number of days in the range, inclusive', function () {
    $this->capacity->allocate($this->machine, $this->today->subDays(2), 900);
    $this->capacity->allocate($this->machine, $this->today->subDay(), 900);
    $this->capacity->allocate($this->machine, $this->today, 900);

    $utilisation = $this->capacity->forMachine($this->machine, $this->today->subDays(2), $this->today);

    expect($utilisation->days)->toBe(3)
        ->and($utilisation->capacityQty)->toBe(3000.0)
        ->and($utilisation->allocatedQty)->toBe(2700.0)
        ->and($utilisation->percent())->toBe(90.0);
});

it('ignores allocations outside the range', function () {
    $this->capacity->allocate($this->machine, $this->today->subDays(10), 800);
    $this->capacity->allocate($this->machine, $this->today, 200);

    expect($this->capacity->forMachine($this->machine, $this->today, $this->today)->allocatedQty)->toBe(200.0);
});

it('reports unknown rather than zero when a machine has no capacity on file', function () {
    $noCapacity = Machine::query()->where('code', 'JUMBO')->firstOrFail();
    $this->capacity->allocate($noCapacity, $this->today, 300);

    $utilisation = $this->capacity->forMachine($noCapacity, $this->today, $this->today);

    expect($utilisation->capacityQty)->toBeNull()
        ->and($utilisation->percent())->toBeNull()
        ->and($utilisation->freeQty())->toBeNull()
        ->and($utilisation->allocatedQty)->toBe(300.0);
});

it('reports over-commitment above 100% instead of clamping', function () {
    $this->capacity->allocate($this->machine, $this->today, 1250);

    expect($this->capacity->forMachine($this->machine, $this->today, $this->today)->percent())->toBe(125.0);
});

it('aggregates a department across its machines', function () {
    $second = Machine::query()->where('code', 'STENTER-2')->firstOrFail();
    $second->forceFill(['daily_capacity' => 500])->save();

    $this->capacity->allocate($this->machine, $this->today, 600, department: $this->department);
    $this->capacity->allocate($second, $this->today, 100, department: $this->department);

    $utilisation = $this->capacity->forDepartment($this->department, $this->today, $this->today);

    expect($utilisation->capacityQty)->toBe(1500.0)
        ->and($utilisation->allocatedQty)->toBe(700.0)
        ->and(round((float) $utilisation->percent(), 1))->toBe(46.7);
});

it('rejects a zero or negative allocation and a backwards range', function () {
    expect(fn () => $this->capacity->allocate($this->machine, $this->today, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->capacity->forMachine($this->machine, $this->today, $this->today->subDay()))
        ->toThrow(InvalidArgumentException::class);
});

it('logs the allocation as a user action', function () {
    $this->capacity->allocate($this->machine, $this->today, 600, by: $this->ppc);

    $action = UserAction::query()->where('action', ActionKeys::CAPACITY_ALLOCATED)->sole();
    expect($action->subject_type)->toBe(MachineAllocation::class)
        ->and($action->description)->toContain('STENTER-1');
});

it('ignores anything in $extra that is not one of its own columns', function () {
    $other = Machine::query()->where('code', 'STENTER-2')->firstOrFail();

    // PHP's `+` keeps the LEFT operand, so an $extra spread over the computed values would have won.
    $allocation = $this->capacity->allocate($this->machine, $this->today, 600, extra: [
        'note' => 'Planned on the whiteboard',
        'machine_id' => $other->id,
        'allocated_qty' => 1,
        'allocated_on' => $this->today->subYear()->toDateString(),
        'uom' => 'yards',
    ]);

    expect($allocation->machine_id)->toBe($this->machine->id)
        ->and((float) $allocation->allocated_qty)->toBe(600.0)
        ->and($allocation->allocated_on->toDateString())->toBe($this->today->toDateString())
        ->and($allocation->uom)->toBe(Machine::UOM_METRES)
        ->and($allocation->note)->toBe('Planned on the whiteboard');
});

it('never writes to the ledger', function () {
    $this->capacity->allocate($this->machine, $this->today, 600);

    expect(LotMovement::query()->count())->toBe(0)
        ->and(LotBalance::query()->count())->toBe(0);
});

it('serves machine and department utilisation over http', function () {
    $this->capacity->allocate($this->machine, $this->today, 600, department: $this->department);

    $this->actingAs($this->ppc)
        ->getJson('/production/utilisation?from='.$this->today->toDateString().'&to='.$this->today->toDateString().'&department_id='.$this->department->id)
        ->assertOk()
        ->assertJsonPath('range.from', $this->today->toDateString())
        ->assertJsonPath('department.allocated_qty', 600)
        ->assertJsonStructure(['machines' => [['id', 'code', 'name', 'allocated_qty', 'capacity_qty', 'free_qty', 'utilisation_pct', 'days', 'uom']]]);
});

it('exposes capacity on the machines master screen', function () {
    $this->actingAs($this->ppc)->get('/masters/machines')->assertOk()
        ->assertInertia(fn ($page) => $page->where('resource.fields', fn ($fields) => collect($fields)->pluck('name')
            ->intersect(['hourly_capacity', 'daily_capacity', 'capacity_uom'])->count() === 3));

    $this->actingAs($this->ppc)->put("/masters/machines/{$this->machine->id}", [
        'code' => $this->machine->code, 'name' => $this->machine->name, 'departments' => [$this->department->id],
        'is_active' => true, 'hourly_capacity' => 80, 'daily_capacity' => 1920, 'capacity_uom' => Machine::UOM_METRES,
    ])->assertRedirect();

    expect((float) $this->machine->fresh()->daily_capacity)->toBe(1920.0);
});

it('lays the machines form out in whole 12-column rows, with no field straddling a wrap', function () {
    // MasterForm renders one `grid-cols-12` block, so a field whose span crosses a row boundary is orphaned
    // on the next line. Adding the three capacity fields made this worth asserting rather than eyeballing.
    $fields = (new MachineResource)->fields();
    expect($fields)->not->toBeEmpty();

    $used = 0;
    $rows = 1;
    foreach ($fields as $field) {
        $span = $field->toArray()['span'];
        expect($span)->toBeLessThanOrEqual(12);
        if ($used + $span > 12) {
            expect($used)->toBe(12, "a field starts mid-row: the row before it is only {$used}/12");
            $used = 0;
            $rows++;
        }
        $used += $span;
    }

    expect($rows)->toBe(2);
});
