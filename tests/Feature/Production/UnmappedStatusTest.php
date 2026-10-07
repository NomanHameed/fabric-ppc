<?php

use App\Models\User;
use App\Modules\Foundation\Models\StageProcess;
use App\Modules\Production\Models\Lot;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * "Unmapped Production Statuses" (spec §10): the statuses in use that no stage process claims, with their
 * lot counts — and the three things that are deliberately not on the list (a mapped status, a lot with no
 * status at all, and a lot whose stage was pinned by hand).
 */

beforeEach(function () {
    config(['audit.console' => true]); // the suite runs in the console; adding an alias must still be audited
    $this->seed([RolesAndPermissionsSeeder::class, StageSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');          // masters.manage
    $this->viewer = User::factory()->create()->assignRole('viewer');    // masters.view only
});

/** A lot carrying exactly this status, written past the ledger (the point is the status, not how it got there). */
function lotWithStatus(?string $status, array $attrs = []): Lot
{
    $lot = Lot::factory()->create(['greige_qty' => 100]);
    DB::table('lots')->where('id', $lot->id)->update(['current_label' => $status] + $attrs);

    return $lot->fresh();
}

it('lists each unmapped status once with its lot count, busiest first', function () {
    lotWithStatus('WEIRD ONE/');
    lotWithStatus('WEIRD ONE/');
    lotWithStatus('ANOTHER/');
    lotWithStatus('DYEING/');     // mapped by the seed
    lotWithStatus(null);          // no status at all: not a mapping gap

    $this->actingAs($this->ppc)->get('/production/unmapped-statuses')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('production/UnmappedStatuses')
            ->has('statuses', 2)
            ->where('statuses.0.label', 'WEIRD ONE/')
            ->where('statuses.0.normalised', 'WEIRD ONE')
            ->where('statuses.0.lots', 2)
            ->where('statuses.1.label', 'ANOTHER/')
            ->where('statuses.1.lots', 1)
            ->where('statusesTotal', 2)
            ->where('lotsAffected', 3)
            // every lot here is still in stock, so none of them has a stage
            ->where('lotsWithoutStage', 5));
});

it('treats differently written forms of one status as one row only when they are written the same', function () {
    lotWithStatus('WEIRD/');
    lotWithStatus('weird');

    // two different raw strings, so two rows — but the `normalised` column shows they are the same status,
    // which is what tells the operator one alias fixes both.
    $this->actingAs($this->ppc)->get('/production/unmapped-statuses')
        ->assertInertia(fn (AssertableInertia $p) => $p->has('statuses', 2)
            ->where('statuses.0.normalised', 'WEIRD')
            ->where('statuses.1.normalised', 'WEIRD'));
});

it('does not count a lot whose stage was pinned by hand', function () {
    $process = StageProcess::where('code', 'HOLD')->firstOrFail();
    lotWithStatus('WEIRD ONE/');
    lotWithStatus('WEIRD ONE/', [
        'stage_id' => $process->stage_id, 'stage_process_id' => $process->id,
        'stage_overridden_at' => now(), 'stage_overridden_by' => $this->ppc->id,
    ]);

    $this->actingAs($this->ppc)->get('/production/unmapped-statuses')
        ->assertInertia(fn (AssertableInertia $p) => $p->has('statuses', 1)
            ->where('statuses.0.lots', 1)
            ->where('lotsAffected', 1));
});

it('says so when every status in use is mapped', function () {
    lotWithStatus('DYEING/');
    lotWithStatus('DISP 11-09-26/');

    $this->actingAs($this->ppc)->get('/production/unmapped-statuses')
        ->assertInertia(fn (AssertableInertia $p) => $p->has('statuses', 0)
            ->where('statusesTotal', 0)->where('lotsAffected', 0));
});

it('follows the status away as soon as an alias is added', function () {
    lotWithStatus('WEIRD ONE/');
    StageProcess::query()->where('code', 'WIP_UNASSIGNED')
        ->update(['status_aliases' => json_encode(['WIP', 'WEIRD ONE'])]);

    $this->actingAs($this->ppc)->get('/production/unmapped-statuses')
        ->assertInertia(fn (AssertableInertia $p) => $p->has('statuses', 0));
});

it('is gated on masters.manage, not on a read permission', function () {
    $this->actingAs($this->viewer)->get('/production/unmapped-statuses')->assertForbidden();
});

it('is behind authentication', function () {
    $this->get('/production/unmapped-statuses')->assertRedirect('/login');
});

// ---- mapping a status from the report ------------------------------------------------------------------------

it('maps an unmapped status, re-resolves its lots and empties the row', function () {
    $process = StageProcess::where('code', 'WIP_UNASSIGNED')->firstOrFail();
    $a = lotWithStatus('WEIRD ONE/');
    $b = lotWithStatus('weird one');   // the same status, written differently
    $other = lotWithStatus('ANOTHER/');

    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$process->id}/aliases", ['status' => 'WEIRD ONE/'])
        ->assertRedirect();

    // the alias is stored normalised, once
    expect($process->fresh()->status_aliases)->toBe(['WIP', 'WEIRD ONE'])
        // both spellings resolve, with no second alias
        ->and($a->fresh()->stage_process_id)->toBe((int) $process->id)
        ->and($b->fresh()->stage_process_id)->toBe((int) $process->id)
        ->and($b->fresh()->stage_id)->toBe((int) $process->stage_id)
        ->and($other->fresh()->stage_process_id)->toBeNull();

    $this->actingAs($this->ppc)->get('/production/unmapped-statuses')
        ->assertInertia(fn (AssertableInertia $p) => $p->has('statuses', 1)
            ->where('statuses.0.label', 'ANOTHER/'));
});

it('audits the alias, so a re-classification of history has an author', function () {
    $process = StageProcess::where('code', 'WIP_UNASSIGNED')->firstOrFail();
    lotWithStatus('WEIRD ONE/');

    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$process->id}/aliases", ['status' => 'weird one /'])
        ->assertRedirect();

    $audit = $process->fresh()->audits()->latest('id')->first();
    // the audit keeps the attribute as it was written, which for a JSON column is the encoded string
    $aliases = $audit->new_values['status_aliases'];
    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($this->ppc->id)
        ->and(is_string($aliases) ? json_decode($aliases, true) : $aliases)->toBe(['WIP', 'WEIRD ONE']);
});

it('is a no-op when the same mapping is made twice', function () {
    $process = StageProcess::where('code', 'WIP_UNASSIGNED')->firstOrFail();
    lotWithStatus('WEIRD ONE/');

    $this->actingAs($this->ppc)->post("/production/unmapped-statuses/{$process->id}/aliases", ['status' => 'WEIRD ONE/'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->ppc)->post("/production/unmapped-statuses/{$process->id}/aliases", ['status' => 'weird one'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($process->fresh()->status_aliases)->toBe(['WIP', 'WEIRD ONE']);
});

it('refuses a status another process already owns, and names it', function () {
    $process = StageProcess::where('code', 'WIP_UNASSIGNED')->firstOrFail();

    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$process->id}/aliases", ['status' => 'DYEING/'])
        ->assertSessionHasErrors('status');

    expect(session('errors')->first('status'))->toContain('Dyeing')
        ->and($process->fresh()->status_aliases)->toBe(['WIP']);

    // including one claimed through a wildcard
    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$process->id}/aliases", ['status' => 'SOMETHING CANCEL'])
        ->assertSessionHasErrors('status');
    expect(session('errors')->first('status'))->toContain('Cancelled');
});

it('leaves a lot whose stage was pinned by hand, and counts it separately', function () {
    $process = StageProcess::where('code', 'WIP_UNASSIGNED')->firstOrFail();
    $hold = StageProcess::where('code', 'HOLD')->firstOrFail();
    $plain = lotWithStatus('WEIRD ONE/');
    $pinned = lotWithStatus('WEIRD ONE/', [
        'stage_id' => $hold->stage_id, 'stage_process_id' => $hold->id,
        'stage_overridden_at' => now(), 'stage_overridden_by' => $this->ppc->id,
    ]);

    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$process->id}/aliases", ['status' => 'WEIRD ONE/'])
        ->assertRedirect();

    expect($plain->fresh()->stage_process_id)->toBe((int) $process->id)
        ->and($pinned->fresh()->stage_process_id)->toBe((int) $hold->id)
        ->and($pinned->fresh()->stage_overridden_at)->not->toBeNull();
});

it('rejects an alias that says nothing', function (string $status) {
    $process = StageProcess::where('code', 'WIP_UNASSIGNED')->firstOrFail();

    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$process->id}/aliases", ['status' => $status])
        ->assertSessionHasErrors('status');

    expect($process->fresh()->status_aliases)->toBe(['WIP']);
})->with([
    'blank' => [''],
    'spaces' => ['   '],
    'slashes' => [' / / '],
    'punctuation' => ['-- //'],
    'too long' => ['THIS STATUS IS FAR LONGER THAN THE COLUMN ALLOWS'],
]);

it('refuses to map onto a deactivated process', function () {
    $process = StageProcess::where('code', 'WIP_UNASSIGNED')->firstOrFail();
    StageProcess::query()->whereKey($process->id)->update(['is_active' => false]);

    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$process->id}/aliases", ['status' => 'WEIRD ONE/'])
        ->assertSessionHasErrors('stage_process_id');
});

it('refuses the mapping to anyone without masters.manage, before validating', function () {
    $process = StageProcess::where('code', 'WIP_UNASSIGNED')->firstOrFail();

    $this->actingAs($this->viewer)
        ->post("/production/unmapped-statuses/{$process->id}/aliases", ['status' => ['nonsense']])
        ->assertForbidden();

    expect($process->fresh()->status_aliases)->toBe(['WIP']);
});

it('links the row to a lot carrying the status', function () {
    $first = lotWithStatus('WEIRD ONE/');
    lotWithStatus('WEIRD ONE/');

    $this->actingAs($this->ppc)->get('/production/unmapped-statuses')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('statuses.0.lot_id', $first->id)
            ->where('statuses.0.id', $first->id));
});
