<?php

use App\Models\User;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotRouteStep;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->routes = app(LotRouteService::class);
    $this->ledger = app(LedgerService::class);
    $this->lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100, 'design' => 'E1143/119', 'colour' => '520 PLUM']);
    $this->routes->assign($this->lot);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 100);
});

/** Issue everything and process it through the first $n steps, so STEP:<n> holds the metres. */
function processThrough(Lot $lot, int $n): void
{
    $routes = app(LotRouteService::class);
    $ledger = app(LedgerService::class);
    $ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    foreach ($lot->routeSteps()->get()->take($n) as $step) {
        $ledger->post($lot, 'process', $routes->previousPosition($step), $routes->targetPosition($step), 100, 100);
    }
    $routes->refreshStatuses($lot);
}

// ---- index --------------------------------------------------------------------------------------------------

it('shows the lot header and its steps with metres waiting after each one and the locked flag', function () {
    processThrough($this->lot, 2);
    $steps = $this->lot->routeSteps()->get();

    $this->actingAs($this->ppc)->get("/production/routes?lot={$this->lot->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('production/LotRoute')
            ->where('lot.id', $this->lot->id)->where('lot.lot_no', $this->lot->lot_no)->where('lot.po_no', $this->lot->contract->po_no)
            ->where('lot.party', $this->lot->contract->party->name)->where('lot.contract_id', $this->lot->contract_id)
            ->where('lot.design', 'E1143/119')->where('lot.colour', '520 PLUM')->where('lot.po_type', 'HALF BLEACH')->where('lot.state', 'open')
            ->where('lot.stock_qty', 0)->where('lot.issued', 0)->where('lot.wip_qty', 100)->where('lot.finished_qty', 0)
            ->where('lot.current_label', $steps[2]->operation->stage_label)
            ->has('steps', 5)
            ->where('steps.0.id', $steps[0]->id)->where('steps.0.seq', 10)->where('steps.0.operation', $steps[0]->operation->name)
            ->where('steps.0.department', $steps[0]->operation->department->name)->where('steps.0.status', 'done')
            ->where('steps.0.at_position', 0)->where('steps.0.is_added', false)->where('steps.0.locked', true)
            ->where('steps.1.status', 'done')->where('steps.1.at_position', 100)->where('steps.1.locked', true)
            ->where('steps.2.status', 'pending')->where('steps.2.at_position', 0)->where('steps.2.locked', false));
});

it('renders the empty state without a lot and 404s an unknown one', function () {
    $this->actingAs($this->ppc)->get('/production/routes')->assertOk()
        ->assertInertia(fn ($page) => $page->component('production/LotRoute')->where('lot', null)->where('steps', []));
    $this->actingAs($this->ppc)->get('/production/routes?lot=999999')->assertNotFound();
});

// ---- update -------------------------------------------------------------------------------------------------

it('saves a reordered route with an added step and a skipped one in a single save', function () {
    $steps = $this->lot->routeSteps;
    $extra = Operation::factory()->create();
    $payload = [
        ['id' => $steps[0]->id, 'operation_id' => $steps[0]->operation_id, 'skipped' => false],
        ['id' => null, 'operation_id' => $extra->id, 'skipped' => true],
        ['id' => $steps[2]->id, 'operation_id' => $steps[2]->operation_id, 'skipped' => true],
        ['id' => $steps[1]->id, 'operation_id' => $steps[1]->operation_id, 'skipped' => false],
    ];
    $this->actingAs($this->ppc)->put("/production/routes/{$this->lot->id}", ['steps' => $payload])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => __('forms.saved')]);

    $now = $this->lot->routeSteps()->get();
    expect($now->pluck('operation_id')->all())->toBe([$steps[0]->operation_id, $extra->id, $steps[2]->operation_id, $steps[1]->operation_id])
        ->and($now->pluck('seq')->all())->toBe([10, 20, 30, 40])
        ->and($now->pluck('is_added')->all())->toBe([false, true, false, false])
        ->and($now->pluck('status')->all())->toBe(['pending', 'skipped', 'skipped', 'pending'])
        ->and($now[0]->id)->toBe($steps[0]->id)->and($now[2]->id)->toBe($steps[2]->id)->and($now[3]->id)->toBe($steps[1]->id)
        ->and($now->count())->toBe(4)
        ->and(LotRouteStep::query()->whereKey($steps[3]->id)->exists())->toBeFalse();
});

it('un-skips a step and changes a pending step operation on save', function () {
    $steps = $this->lot->routeSteps;
    $this->routes->setSkipped($steps[1], true);
    $other = Operation::factory()->create();
    $payload = $steps->map(fn (LotRouteStep $s) => ['id' => $s->id, 'operation_id' => $s->id === $steps[2]->id ? $other->id : $s->operation_id, 'skipped' => false])->all();

    $this->actingAs($this->ppc)->put("/production/routes/{$this->lot->id}", ['steps' => $payload])->assertSessionHasNoErrors();
    $now = $this->lot->routeSteps()->get();
    expect($now[1]->status)->toBe('pending')->and($now[2]->operation_id)->toBe($other->id)->and($now[2]->is_added)->toBeFalse();
});

it('surfaces a refused edit as an error on steps and leaves the route untouched', function () {
    processThrough($this->lot, 1);
    $steps = $this->lot->routeSteps()->get();
    $before = $steps->map(fn (LotRouteStep $s) => [$s->id, $s->seq, $s->operation_id, $s->status])->all();

    // dropping the done step
    $payload = $steps->slice(1)->map(fn (LotRouteStep $s) => ['id' => $s->id, 'operation_id' => $s->operation_id, 'skipped' => false])->values()->all();
    $this->actingAs($this->ppc)->put("/production/routes/{$this->lot->id}", ['steps' => $payload])
        ->assertSessionHasErrors(['steps' => __('production.errors.route_step_in_progress')]);

    // a new step before the done one would move the metres it takes from
    $extra = Operation::factory()->create();
    $payload = [['id' => null, 'operation_id' => $extra->id], ...$steps->map(fn (LotRouteStep $s) => ['id' => $s->id, 'operation_id' => $s->operation_id])->all()];
    $this->actingAs($this->ppc)->put("/production/routes/{$this->lot->id}", ['steps' => $payload])
        ->assertSessionHasErrors(['steps' => __('production.errors.route_step_position_locked')]);

    $after = $this->lot->routeSteps()->get()->map(fn (LotRouteStep $s) => [$s->id, $s->seq, $s->operation_id, $s->status])->all();
    expect($after)->toBe($before)->and(LotRouteStep::query()->where('lot_id', $this->lot->id)->count())->toBe(5);
});

it('rolls the added step back when the skip of an existing one is refused', function () {
    processThrough($this->lot, 4); // s1..s4 done, metres at STEP:s4 waiting for s5, the last step
    $steps = $this->lot->routeSteps()->get();
    $extra = Operation::factory()->create();
    $payload = $steps->map(fn (LotRouteStep $s) => ['id' => $s->id, 'operation_id' => $s->operation_id, 'skipped' => false])->all();
    $payload[] = ['id' => null, 'operation_id' => $extra->id, 'skipped' => false]; // a 6th step after s5: replace() accepts it
    $payload[3]['skipped'] = true; // s4 has metres waiting after it: the skip is refused, and the 6th step must go with it

    $this->actingAs($this->ppc)->put("/production/routes/{$this->lot->id}", ['steps' => $payload])
        ->assertSessionHasErrors(['steps.3.skipped' => __('production.errors.route_step_in_progress')]);
    $now = $this->lot->routeSteps()->get();
    expect($now->count())->toBe(5)->and($now->pluck('status')->all())->toBe(['done', 'done', 'done', 'done', 'pending'])
        ->and($now->pluck('operation_id')->all())->not->toContain($extra->id);
});

it('validates the steps payload', function () {
    $this->actingAs($this->ppc)->put("/production/routes/{$this->lot->id}", [])->assertSessionHasErrors(['steps']);
    $this->actingAs($this->ppc)->put("/production/routes/{$this->lot->id}", ['steps' => [['id' => null, 'operation_id' => 999999]]])
        ->assertSessionHasErrors(['steps.0.operation_id']);
    $this->actingAs($this->ppc)->put("/production/routes/{$this->lot->id}", ['steps' => [['id' => 'x', 'operation_id' => Operation::query()->first()->id]]])
        ->assertSessionHasErrors(['steps.0.id']);
    $this->actingAs($this->ppc)->put('/production/routes/999999', ['steps' => []])->assertNotFound();
    expect($this->lot->routeSteps()->count())->toBe(5);
});

// ---- skip ---------------------------------------------------------------------------------------------------

it('skips and un-skips one step, refuses one with metres waiting, and 404s a step of another lot', function () {
    $steps = $this->lot->routeSteps()->get();
    $url = fn (LotRouteStep $s) => "/production/routes/{$this->lot->id}/steps/{$s->id}/skip";

    $this->actingAs($this->ppc)->post($url($steps[1]), ['skipped' => true])->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => __('forms.saved')]);
    expect($steps[1]->fresh()->status)->toBe('skipped');
    $this->actingAs($this->ppc)->post($url($steps[1]), ['skipped' => false])->assertSessionHasNoErrors();
    expect($steps[1]->fresh()->status)->toBe('pending');
    $this->actingAs($this->ppc)->post($url($steps[1]), [])->assertSessionHasErrors(['skipped']);

    processThrough($this->lot, 1);
    $this->actingAs($this->ppc)->post($url($steps[0]), ['skipped' => true])
        ->assertSessionHasErrors(['skipped' => __('production.errors.route_step_in_progress')]);
    expect($steps[0]->fresh()->status)->toBe('done');

    $other = Lot::factory()->create(['po_type' => 'HALF BLEACH']);
    $this->routes->assign($other);
    $this->actingAs($this->ppc)->post("/production/routes/{$this->lot->id}/steps/{$other->routeSteps->first()->id}/skip", ['skipped' => true])->assertNotFound();
    $this->actingAs($this->ppc)->post("/production/routes/{$this->lot->id}/steps/999999/skip", ['skipped' => true])->assertNotFound();
});

// ---- apply template -----------------------------------------------------------------------------------------

it('applies a template to one lot and refuses once the lot has production', function () {
    $t = RouteTemplate::query()->where('name', 'Dyed - Standard')->firstOrFail();
    $this->actingAs($this->ppc)->post("/production/routes/{$this->lot->id}/apply", ['route_template_id' => $t->id])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => __('production.route.applied_lot', ['n' => 7, 'lot' => $this->lot->lot_no])]);
    expect($this->lot->routeSteps()->count())->toBe(7);

    processThrough($this->lot, 1);
    $half = RouteTemplate::query()->where('name', 'Half Bleach')->firstOrFail();
    $this->actingAs($this->ppc)->post("/production/routes/{$this->lot->id}/apply", ['route_template_id' => $half->id])
        ->assertSessionHasErrors(['route_template_id' => __('production.errors.route_in_progress')]);
    expect($this->lot->routeSteps()->count())->toBe(7);

    $this->actingAs($this->ppc)->post("/production/routes/{$this->lot->id}/apply", ['route_template_id' => 999999])->assertSessionHasErrors(['route_template_id']);
    $this->actingAs($this->ppc)->post('/production/routes/999999/apply', ['route_template_id' => $t->id])->assertNotFound();
});

it('applies a template to every open lot of the PO without production and toasts the count', function () {
    $sibling = Lot::factory()->create(['contract_id' => $this->lot->contract_id, 'po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($sibling);
    $this->ledger->post($sibling, 'import', null, Position::stock(), 0, 100);
    $held = Lot::factory()->create(['contract_id' => $this->lot->contract_id, 'po_type' => 'HALF BLEACH', 'state' => 'hold']);
    $this->routes->assign($held);
    $t = RouteTemplate::query()->where('name', 'Dyed - Standard')->firstOrFail();

    $this->actingAs($this->ppc)->post("/production/routes/contract/{$this->lot->contract_id}", ['route_template_id' => $t->id])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => __('production.route.applied', ['n' => 2, 'po' => $this->lot->contract->po_no])]);
    expect($this->lot->routeSteps()->count())->toBe(7)->and($sibling->routeSteps()->count())->toBe(7)->and($held->routeSteps()->count())->toBe(5);

    processThrough($this->lot, 1);
    processThrough($sibling, 1);
    $half = RouteTemplate::query()->where('name', 'Half Bleach')->firstOrFail();
    $this->actingAs($this->ppc)->post("/production/routes/contract/{$this->lot->contract_id}", ['route_template_id' => $half->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'info', 'message' => __('production.route.applied_none', ['n' => 0, 'po' => $this->lot->contract->po_no])]);
    expect($this->lot->routeSteps()->count())->toBe(7);

    $this->actingAs($this->ppc)->post("/production/routes/contract/{$this->lot->contract_id}", [])->assertSessionHasErrors(['route_template_id']);
    $this->actingAs($this->ppc)->post('/production/routes/contract/999999', ['route_template_id' => $t->id])->assertNotFound();
});

// ---- permissions and LOV ------------------------------------------------------------------------------------

it('requires production.route.manage on every route', function () {
    $this->get('/production/routes')->assertRedirect('/login');
    $step = $this->lot->routeSteps->first();
    $t = RouteTemplate::query()->where('name', 'Half Bleach')->firstOrFail();
    $calls = fn (User $user) => [
        $this->actingAs($user)->get("/production/routes?lot={$this->lot->id}"),
        $this->actingAs($user)->put("/production/routes/{$this->lot->id}", ['steps' => []]),
        $this->actingAs($user)->post("/production/routes/{$this->lot->id}/steps/{$step->id}/skip", ['skipped' => true]),
        $this->actingAs($user)->post("/production/routes/{$this->lot->id}/apply", ['route_template_id' => $t->id]),
        $this->actingAs($user)->post("/production/routes/contract/{$this->lot->contract_id}", ['route_template_id' => $t->id]),
    ];
    foreach (['viewer', 'dept_incharge', 'colour_lab', 'dispatch'] as $role) {
        foreach ($calls(User::factory()->create()->assignRole($role)) as $response) {
            $response->assertForbidden();
        }
    }
    expect($this->lot->routeSteps()->count())->toBe(5)->and($step->fresh()->status)->toBe('pending');

    foreach (['manager', 'admin'] as $role) {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user)->get("/production/routes?lot={$this->lot->id}")->assertOk();
    }
});

it('lists active route templates on the route-templates LOV with their step count', function () {
    RouteTemplate::query()->where('name', 'Default')->update(['is_active' => false]);

    $rows = collect($this->actingAs($this->ppc)->getJson('/lov/route-templates')->assertOk()
        ->assertJsonPath('columns.0.key', 'label')->assertJsonPath('columns.3.key', 'steps')->json('data'));
    expect($rows->pluck('label')->all())->toBe(['Dyed - Standard', 'Half Bleach', 'Printed - Standard'])
        ->and($rows->firstWhere('label', 'Half Bleach'))->toMatchArray(['po_type' => 'HALF BLEACH', 'steps' => 5, 'code' => 'Half Bleach']);

    $exact = $this->actingAs($this->ppc)->getJson('/lov/route-templates?exact=half bleach')->assertOk()->json('data');
    expect($exact)->toHaveCount(1)->and($exact[0]['steps'])->toBe(5);
    $search = collect($this->actingAs($this->ppc)->getJson('/lov/route-templates?q=printed')->assertOk()->json('data'));
    expect($search->pluck('label')->all())->toBe(['Printed - Standard']);
});
