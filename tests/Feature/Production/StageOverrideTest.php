<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Shift;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Services\ProductionSheetService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * The manual stage override: PPC pins a lot's reporting stage, the ledger stops re-deriving it until the
 * override is cleared, and nobody without `production.route.manage` can touch either column by any route.
 */

beforeEach(function () {
    config(['audit.console' => true]); // the override must be audited, and the suite runs in the console
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, StageSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->viewer = User::factory()->create()->assignRole('viewer');
    $this->colourLab = User::factory()->create()->assignRole('colour_lab');
    $this->ledger = app(LedgerService::class);
    $this->routes = app(LotRouteService::class);

    $this->hold = StageProcess::query()->where('code', 'HOLD')->firstOrFail();
    $this->dyeing = StageProcess::query()->where('code', 'DYEING')->firstOrFail();

    $this->lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 1000, 'colour_status' => 'APPROVED']);
    $this->routes->assign($this->lot);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 1000);
});

/** Pin the lot on the Hold process, as the screen does. */
function pinHold(): array
{
    return ['stage_id' => test()->hold->stage_id, 'stage_process_id' => test()->hold->id];
}

// ---- setting and clearing -------------------------------------------------------------------------------------

it('pins the stage, records who and when, and audits it', function () {
    $this->actingAs($this->ppc)->put("/production/lots/{$this->lot->id}/stage", pinHold())->assertRedirect();

    $lot = $this->lot->fresh();
    expect($lot->stage_id)->toBe((int) $this->hold->stage_id)
        ->and($lot->stage_process_id)->toBe((int) $this->hold->id)
        ->and($lot->stage_overridden_at)->not->toBeNull()
        ->and($lot->stage_overridden_by)->toBe($this->ppc->id)
        ->and($lot->audits()->latest('id')->first()->new_values)
        ->toHaveKeys(['stage_id', 'stage_process_id', 'stage_overridden_at', 'stage_overridden_by']);
});

it('keeps the pinned stage through an issue and a production sheet while the status moves on', function () {
    $this->actingAs($this->ppc)->put("/production/lots/{$this->lot->id}/stage", pinHold())->assertRedirect();
    $pinned = $this->lot->fresh();

    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1000, 1000);

    $sheets = app(ProductionSheetService::class);
    $sheets->save(null, [
        'sheet_date' => '2026-09-11',
        'shift_id' => Shift::where('code', 'A')->value('id'),
        'department_id' => Department::where('code', 'SINGEING')->value('id'),
        'machine_id' => Machine::where('code', 'SINGEING')->value('id'),
    ], [[
        'lot_id' => $this->lot->id,
        'operation_id' => Operation::where('name', 'SINGING')->value('id'),
        'qty_in' => 1000, 'qty_out' => 1000,
    ]], $this->ppc);

    $after = $this->lot->fresh();
    expect($after->current_label)->not->toBe($pinned->current_label)   // the status did move
        ->and($after->stage_id)->toBe($pinned->stage_id)               // the stage did not
        ->and($after->stage_process_id)->toBe($pinned->stage_process_id)
        ->and($after->wip_qty)->not->toEqual($pinned->wip_qty);        // and the quantities are still written
});

it('re-derives the stage the moment the override is cleared', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1000, 1000);
    $derived = $this->lot->fresh()->stage_process_id;
    expect($derived)->not->toBeNull();

    $this->actingAs($this->ppc)->put("/production/lots/{$this->lot->id}/stage", pinHold())->assertRedirect();
    expect($this->lot->fresh()->stage_process_id)->toBe((int) $this->hold->id);

    $this->actingAs($this->ppc)->delete("/production/lots/{$this->lot->id}/stage")->assertRedirect();

    $lot = $this->lot->fresh();
    expect($lot->stage_overridden_at)->toBeNull()
        ->and($lot->stage_overridden_by)->toBeNull()
        ->and($lot->stage_process_id)->toBe($derived);   // back to the derived value at once
});

it('clears to null when the lot\'s own status is not mapped', function () {
    $this->actingAs($this->ppc)->put("/production/lots/{$this->lot->id}/stage", pinHold())->assertRedirect();
    DB::table('lots')->where('id', $this->lot->id)->update(['current_label' => 'NOT A STATUS/']);

    $this->actingAs($this->ppc)->delete("/production/lots/{$this->lot->id}/stage")->assertRedirect();

    $lot = $this->lot->fresh();
    expect($lot->stage_id)->toBeNull()->and($lot->stage_process_id)->toBeNull()
        ->and($lot->stage_overridden_at)->toBeNull();
});

// ---- the permission boundary ---------------------------------------------------------------------------------

it('refuses the override to anyone without production.route.manage', function () {
    foreach ([$this->viewer, $this->colourLab] as $user) {
        $this->actingAs($user)->put("/production/lots/{$this->lot->id}/stage", pinHold())->assertForbidden();
        $this->actingAs($user)->delete("/production/lots/{$this->lot->id}/stage")->assertForbidden();
    }

    expect($this->lot->fresh()->stage_overridden_at)->toBeNull();
});

it('refuses before validating, so a malformed payload still gets 403 and not a 422', function () {
    $this->actingAs($this->viewer)
        ->put("/production/lots/{$this->lot->id}/stage", ['stage_id' => ['nonsense'], 'stage_process_id' => null])
        ->assertForbidden();
});

it('cannot be written through the ordinary lot update, whatever shape the payload takes', function () {
    $lot = $this->lot;
    $payloads = [
        ['colour_status' => 'APPROVED', 'stage_id' => $this->hold->stage_id, 'stage_process_id' => $this->hold->id],
        ['remarks' => 'x', 'stage_overridden_at' => now()->toDateTimeString(), 'stage_overridden_by' => $this->ppc->id],
        ['remarks' => 'y', 'stage' => ['id' => $this->hold->stage_id], 'stage_process' => ['id' => $this->hold->id]],
        ['remarks' => 'z', 'lot.stage_id' => $this->hold->stage_id],
        // a POST spoofed as the PUT the screen uses
        ['_method' => 'PUT', 'remarks' => 'w', 'stage_id' => $this->hold->stage_id],
    ];

    foreach ($payloads as $payload) {
        $this->actingAs($this->ppc)->put("/production/lots/{$lot->id}", $payload);
        $fresh = $lot->fresh();
        expect($fresh->stage_overridden_at)->toBeNull()
            ->and($fresh->stage_id)->toBe($lot->stage_id)
            ->and($fresh->stage_process_id)->toBe($lot->stage_process_id);
    }

    $this->actingAs($this->ppc)->post("/production/lots/{$lot->id}", ['_method' => 'PUT', 'remarks' => 'v', 'stage_id' => $this->hold->stage_id]);
    expect($lot->fresh()->stage_overridden_at)->toBeNull();
});

// ---- what may be pinned -------------------------------------------------------------------------------------

it('rejects a process that belongs to another stage', function () {
    $this->actingAs($this->ppc)
        ->put("/production/lots/{$this->lot->id}/stage", ['stage_id' => $this->hold->stage_id, 'stage_process_id' => $this->dyeing->id])
        ->assertSessionHasErrors('stage_process_id');

    expect($this->lot->fresh()->stage_overridden_at)->toBeNull();
});

it('rejects an inactive stage or an inactive process', function () {
    Stage::query()->whereKey($this->hold->stage_id)->update(['is_active' => false]);
    $this->actingAs($this->ppc)->put("/production/lots/{$this->lot->id}/stage", pinHold())
        ->assertSessionHasErrors('stage_id');

    Stage::query()->whereKey($this->hold->stage_id)->update(['is_active' => true]);
    StageProcess::query()->whereKey($this->hold->id)->update(['is_active' => false]);
    $this->actingAs($this->ppc)->put("/production/lots/{$this->lot->id}/stage", pinHold())
        ->assertSessionHasErrors('stage_process_id');

    expect($this->lot->fresh()->stage_overridden_at)->toBeNull();
});

it('rejects a stage or process that does not exist', function () {
    $this->actingAs($this->ppc)->put("/production/lots/{$this->lot->id}/stage", ['stage_id' => 999999, 'stage_process_id' => 999999])
        ->assertSessionHasErrors(['stage_id', 'stage_process_id']);

    $this->actingAs($this->ppc)->put("/production/lots/{$this->lot->id}/stage", [])
        ->assertSessionHasErrors(['stage_id', 'stage_process_id']);
});

// ---- the screen ----------------------------------------------------------------------------------------------

it('shows the stage, the override and who set it on the Lots screen', function () {
    $this->actingAs($this->ppc)->put("/production/lots/{$this->lot->id}/stage", pinHold())->assertRedirect();

    $this->actingAs($this->ppc)->get('/production/lots')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('lots.0.stage_id', (int) $this->hold->stage_id)
            ->where('lots.0.stage', Stage::query()->whereKey($this->hold->stage_id)->value('name'))
            ->where('lots.0.stage_process_id', (int) $this->hold->id)
            ->where('lots.0.stage_process', $this->hold->name)
            ->where('lots.0.stage_overridden', true)
            ->where('lots.0.stage_overridden_by', $this->ppc->name)
            ->where('can.stage', true));

    $this->actingAs($this->viewer)->get('/production/lots')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('can.stage', false));
});

it('queries lots by stage and by stage process', function () {
    $this->actingAs($this->ppc)->put("/production/lots/{$this->lot->id}/stage", pinHold())->assertRedirect();
    $other = Lot::factory()->create(['greige_qty' => 10]);
    $this->ledger->post($other, 'import', null, Position::stock(), 0, 10);

    $this->actingAs($this->ppc)->get('/production/lots?q[stage]=Exception%25')
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 1)->where('lots.0.id', $this->lot->id));

    $this->actingAs($this->ppc)->get('/production/lots?q[stage_process]=Hold')
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 1)->where('lots.0.id', $this->lot->id));

    // a lot with no stage is still listed by the default query (left join, not inner)
    $this->actingAs($this->ppc)->get('/production/lots')
        ->assertInertia(fn (AssertableInertia $p) => $p->has('lots', 2));
});

// ---- the backfill leaves them alone -------------------------------------------------------------------------

it('skips overridden lots in production:sync-stages and counts them separately', function () {
    User::factory()->create()->assignRole('admin');
    $this->actingAs($this->ppc)->put("/production/lots/{$this->lot->id}/stage", pinHold())->assertRedirect();
    DB::table('lots')->where('id', $this->lot->id)->update(['current_label' => 'DYEING/']);

    $this->artisan('production:sync-stages')
        ->expectsOutputToContain('1 skipped (stage overridden by hand)')
        ->assertSuccessful();

    expect($this->lot->fresh()->stage_process_id)->toBe((int) $this->hold->id);
});
