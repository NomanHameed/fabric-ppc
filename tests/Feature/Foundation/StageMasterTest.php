<?php

use App\Models\User;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use App\Modules\Production\Models\Lot;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StageSeeder;

/*
 * The Stages and Stage Processes masters (spec §18) and the read API behind the two selects (spec §16).
 * The aliases are edited on the **Stages** master as one comma-separated line and stored as JSON — that
 * round trip is the mapping itself, so it is tested rather than assumed. A stage process no longer carries
 * aliases: resolution is stage-only.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, StageSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');       // masters.view + masters.manage
    $this->viewer = User::factory()->create()->assignRole('viewer'); // masters.view only
    $this->nobody = User::factory()->create();                       // no role at all
});

// ---- the masters ---------------------------------------------------------------------------------------------

it('lists both masters with their field metadata', function () {
    $this->viewer->refresh();

    $this->actingAs($this->viewer)->get('/masters/stages')->assertOk()
        ->assertInertia(fn ($page) => $page->component('masters/MasterForm')
            ->where('resource.key', 'stages')
            ->where('resource.canEdit', false)
            ->has('resource.fields', 6)
            ->has('rows', 15));

    $this->actingAs($this->ppc)->get('/masters/stage_processes')->assertOk()
        ->assertInertia(fn ($page) => $page->where('resource.key', 'stage_processes')
            ->where('resource.canEdit', true)
            ->has('rows', StageProcess::count()));
});

it('creates, updates and deletes a stage', function () {
    $this->actingAs($this->ppc)->post('/masters/stages', [
        'code' => 'REWORK', 'name' => 'Rework', 'description' => 'Sent back in', 'sort_order' => 160, 'is_active' => true,
    ])->assertRedirect();
    $stage = Stage::where('code', 'REWORK')->firstOrFail();

    $this->actingAs($this->ppc)->put("/masters/stages/{$stage->id}", [
        'code' => 'REWORK', 'name' => 'Rework (floor)', 'sort_order' => 165, 'is_active' => false,
    ])->assertRedirect();
    expect($stage->fresh()->name)->toBe('Rework (floor)')->and($stage->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->ppc)->delete("/masters/stages/{$stage->id}")->assertRedirect();
    expect(Stage::where('code', 'REWORK')->exists())->toBeFalse();
});

it('refuses to delete a stage its processes still point at, so it is deactivated instead', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();

    $this->actingAs($this->ppc)->delete("/masters/stages/{$stage->id}")->assertSessionHasErrors('record');
    expect(Stage::whereKey($stage->id)->exists())->toBeTrue();
});

it('refuses to delete a stage a lot still points at', function () {
    $stage = Stage::where('code', 'GREIGE')->firstOrFail();   // no processes, so only the lot can refuse it
    Lot::factory()->create(['stage_id' => $stage->id]);

    $this->actingAs($this->ppc)->delete("/masters/stages/{$stage->id}")->assertSessionHasErrors('record');
    expect(Stage::whereKey($stage->id)->exists())->toBeTrue();
});

it('lets a process a lot once pointed at be deleted, because nothing references one any more', function () {
    $process = StageProcess::where('code', 'DYEING')->firstOrFail();
    Lot::factory()->create(['stage_id' => $process->stage_id]);

    $this->actingAs($this->ppc)->delete("/masters/stage_processes/{$process->id}")->assertRedirect()
        ->assertSessionHasNoErrors();
    expect(StageProcess::whereKey($process->id)->exists())->toBeFalse();
});

it('validates the stage code', function () {
    $this->actingAs($this->ppc)->post('/masters/stages', ['code' => 'DYEING', 'name' => 'Dup', 'sort_order' => 1])
        ->assertSessionHasErrors('code');
    $this->actingAs($this->ppc)->post('/masters/stages', ['code' => 'bad code!', 'name' => 'X', 'sort_order' => 1])
        ->assertSessionHasErrors('code');
});

it('stores a stage\'s aliases as JSON and shows them back as one line', function () {
    $this->actingAs($this->ppc)->post('/masters/stages', [
        'code' => 'OVER_DYE', 'name' => 'Over-dyeing', 'sort_order' => 160,
        'status_aliases' => ' OVER DYE ,, OVERDYE/ , OVER DYE ', 'is_active' => true,
    ])->assertRedirect();

    $stage = Stage::where('code', 'OVER_DYE')->firstOrFail();
    // trimmed, blanks dropped, repeats dropped, kept exactly as typed otherwise
    expect($stage->status_aliases)->toBe(['OVER DYE', 'OVERDYE/']);

    $this->actingAs($this->ppc)->get('/masters/stages')
        ->assertInertia(fn ($page) => $page->where(
            'rows.'.rowIndexOf($page->toArray()['props']['rows'], $stage->id).'.status_aliases',
            'OVER DYE, OVERDYE/',
        ));

    // clearing the line empties the mapping rather than leaving the old aliases behind
    $this->actingAs($this->ppc)->put("/masters/stages/{$stage->id}", [
        'code' => 'OVER_DYE', 'name' => 'Over-dyeing', 'sort_order' => 160,
        'status_aliases' => '', 'is_active' => true,
    ])->assertRedirect();
    expect($stage->fresh()->status_aliases)->toBe([]);
});

it('validates a process code within its stage and the quantity type', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();
    $base = ['stage_id' => $stage->id, 'name' => 'X', 'sort_order' => 1];

    $this->actingAs($this->ppc)->post('/masters/stage_processes', $base + ['code' => 'DYEING'])
        ->assertSessionHasErrors('code');
    $this->actingAs($this->ppc)->post('/masters/stage_processes', $base + ['code' => 'NEW', 'quantity_type' => 'nonsense'])
        ->assertSessionHasErrors('quantity_type');
    // the same code under a different stage is fine: the unique key is (stage, code)
    $this->actingAs($this->ppc)->post('/masters/stage_processes', [
        'stage_id' => Stage::where('code', 'PRINTING')->value('id'), 'code' => 'DYEING', 'name' => 'Dyed print', 'sort_order' => 90,
    ])->assertRedirect()->assertSessionHasNoErrors();
});

it('forbids editing either master without masters.manage', function () {
    $this->actingAs($this->viewer)->post('/masters/stages', ['code' => 'X', 'name' => 'X', 'sort_order' => 1])->assertForbidden();
    $this->actingAs($this->viewer)->post('/masters/stage_processes', ['stage_id' => 1, 'code' => 'X', 'name' => 'X', 'sort_order' => 1])
        ->assertForbidden();
});

// ---- the API ------------------------------------------------------------------------------------------------

it('lists the active stages with their process counts', function () {
    Stage::query()->where('code', 'COMMERCIAL')->update(['is_active' => false]);
    $inactive = Stage::factory()->create(['code' => 'GONE', 'name' => 'Gone', 'is_active' => false]);

    $res = $this->actingAs($this->viewer)->getJson('/api/stages')->assertOk();

    $codes = array_column($res->json('data'), 'code');
    expect($codes)->toContain('DYEING')->and($codes)->not->toContain('GONE')
        ->and($res->json('data.0.code'))->toBe('GREIGE')                     // in sort order
        ->and($res->json('data.0.processes_count'))->toBe(0)
        ->and($res->json('data.1.code'))->toBe('PREP')
        ->and($res->json('data.1.processes_count'))->toBe(2);
    expect($inactive->fresh()->is_active)->toBeFalse();
});

it('returns one stage with its active processes', function () {
    $stage = Stage::where('code', 'FINISHING')->firstOrFail();
    StageProcess::query()->where('stage_id', $stage->id)->where('code', 'SANFO_CAL_RP')->update(['is_active' => false]);

    $res = $this->actingAs($this->viewer)->getJson("/api/stages/{$stage->id}")->assertOk();

    expect($res->json('data.code'))->toBe('FINISHING')
        ->and(array_column($res->json('data.processes'), 'code'))->not->toContain('SANFO_CAL_RP')
        ->and(array_column($res->json('data.processes'), 'code'))->toContain('SANFO_CAL');
});

it('returns the processes of a stage with their flags, and the aliases on the stage itself', function () {
    $stage = Stage::where('code', 'DISPATCH')->firstOrFail();

    $res = $this->actingAs($this->viewer)->getJson("/api/stages/{$stage->id}/processes")->assertOk();

    expect($res->json('data.0.code'))->toBe('DISPATCH')
        ->and($res->json('data.0.stage_id'))->toBe($stage->id)
        ->and($res->json('data.0.quantity_type'))->toBe('dispatched')
        ->and($res->json('data.0.is_dispatch'))->toBeTrue()
        ->and($res->json('data.0.is_wip'))->toBeFalse()
        // the mapping lives on the stage now
        ->and($stage->aliasList())->toBe(['DISPATCHED', 'RF-DISP', 'DISP *'])
        ->and(StageProcess::query()->whereKey($res->json('data.0.id'))->firstOrFail()->aliasList())->toBe([]);
});

it('includes deactivated processes only when asked, and still answers for one by id', function () {
    $process = StageProcess::where('code', 'SANFO_CAL_RP')->firstOrFail();
    StageProcess::query()->whereKey($process->id)->update(['is_active' => false]);

    $this->actingAs($this->viewer)->getJson("/api/stages/{$process->stage_id}/processes")
        ->assertOk()->assertJsonMissing(['code' => 'SANFO_CAL_RP']);

    $this->actingAs($this->viewer)->getJson("/api/stages/{$process->stage_id}/processes?include_inactive=1")
        ->assertOk()->assertJsonFragment(['code' => 'SANFO_CAL_RP']);

    // a screen can still be showing it, so it must remain nameable
    $this->actingAs($this->viewer)->getJson("/api/stage-processes/{$process->id}")->assertOk()
        ->assertJsonPath('data.code', 'SANFO_CAL_RP')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.stage.code', 'FINISHING');
});

it('404s for a stage or process that does not exist', function () {
    $this->actingAs($this->viewer)->getJson('/api/stages/999999')->assertNotFound();
    $this->actingAs($this->viewer)->getJson('/api/stages/999999/processes')->assertNotFound();
    $this->actingAs($this->viewer)->getJson('/api/stage-processes/999999')->assertNotFound();
});

it('needs masters.view', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();
    $process = StageProcess::where('code', 'DYEING')->firstOrFail();

    foreach (['/api/stages', "/api/stages/{$stage->id}", "/api/stages/{$stage->id}/processes", "/api/stage-processes/{$process->id}"] as $url) {
        $this->actingAs($this->nobody)->getJson($url)->assertForbidden();
    }
});

it('needs a session', function () {
    $stage = Stage::where('code', 'DYEING')->firstOrFail();

    foreach (['/api/stages', "/api/stages/{$stage->id}", "/api/stages/{$stage->id}/processes"] as $url) {
        $this->getJson($url)->assertUnauthorized();
    }
});

/** The row index of a record in the master screen's rows (they come back in the resource's own order). */
function rowIndexOf(array $rows, int $id): int
{
    foreach (array_values($rows) as $i => $row) {
        if ((int) $row['id'] === $id) {
            return $i;
        }
    }

    throw new RuntimeException("row {$id} is not on the screen");
}
