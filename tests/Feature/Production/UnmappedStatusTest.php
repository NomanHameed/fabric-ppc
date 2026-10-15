<?php

use App\Models\User;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Production\Models\Lot;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * "Unmapped Production Statuses" (spec §10): the statuses in use that no **stage** claims, with their lot
 * counts — and the three things that are deliberately not on the list (a mapped status, a lot with no status
 * at all, and a lot whose stage was pinned by hand).
 *
 * The mapping target is a stage: resolution is stage-only and the aliases live on `stages.status_aliases`.
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
    $lot = Lot::factory()->create(['greige_received_oracle' => 100]);
    DB::table('lots')->where('id', $lot->id)->update(['status_oracle' => $status] + $attrs);

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
    $stage = Stage::where('code', 'EXCEPTION')->firstOrFail();
    lotWithStatus('WEIRD ONE/');
    lotWithStatus('WEIRD ONE/', [
        'stage_id' => $stage->id,
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
    Stage::query()->where('code', 'RETURN')
        ->update(['status_aliases' => json_encode(['RTF', 'WEIRD ONE'])]);

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
    $stage = Stage::where('code', 'RETURN')->firstOrFail();
    $a = lotWithStatus('WEIRD ONE/');
    $b = lotWithStatus('weird one');   // the same status, written differently
    $other = lotWithStatus('ANOTHER/');

    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$stage->id}/aliases", ['status' => 'WEIRD ONE/'])
        ->assertRedirect()
        // the whole rendered sentence, so a renamed placeholder (`:stage` for the stage, never `:process`)
        // leaves a literal `:…` in the toast and fails here
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => __('production.unmapped.mapped', [
            'status' => 'WEIRD ONE', 'stage' => $stage->name, 'lots' => 2, 'skipped' => 0,
        ])]);
    expect(session('inertia.flash_data.toast')['message'])->toContain($stage->name)
        ->not->toContain(':process')->not->toContain(':stage');

    // the alias is stored normalised, once
    expect($stage->fresh()->status_aliases)->toBe(['RTF', 'WEIRD ONE'])
        // both spellings resolve, with no second alias
        ->and($a->fresh()->stage_id)->toBe((int) $stage->id)
        ->and($b->fresh()->stage_id)->toBe((int) $stage->id)
        ->and($other->fresh()->stage_id)->toBeNull();

    $this->actingAs($this->ppc)->get('/production/unmapped-statuses')
        ->assertInertia(fn (AssertableInertia $p) => $p->has('statuses', 1)
            ->where('statuses.0.label', 'ANOTHER/'));
});

it('audits the alias, so a re-classification of history has an author', function () {
    $stage = Stage::where('code', 'RETURN')->firstOrFail();
    lotWithStatus('WEIRD ONE/');

    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$stage->id}/aliases", ['status' => 'weird one /'])
        ->assertRedirect();

    $audit = $stage->fresh()->audits()->latest('id')->first();
    // the audit keeps the attribute as it was written, which for a JSON column is the encoded string
    $aliases = $audit->new_values['status_aliases'];
    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($this->ppc->id)
        ->and(is_string($aliases) ? json_decode($aliases, true) : $aliases)->toBe(['RTF', 'WEIRD ONE']);
});

it('is a no-op when the same mapping is made twice', function () {
    $stage = Stage::where('code', 'RETURN')->firstOrFail();
    lotWithStatus('WEIRD ONE/');

    $this->actingAs($this->ppc)->post("/production/unmapped-statuses/{$stage->id}/aliases", ['status' => 'WEIRD ONE/'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->ppc)->post("/production/unmapped-statuses/{$stage->id}/aliases", ['status' => 'weird one'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($stage->fresh()->status_aliases)->toBe(['RTF', 'WEIRD ONE']);
});

it('refuses a status another stage already owns, and names it', function () {
    $stage = Stage::where('code', 'RETURN')->firstOrFail();

    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$stage->id}/aliases", ['status' => 'DYEING/'])
        ->assertSessionHasErrors('status');

    // the whole sentence: the owning stage goes into `:stage`, so a renamed placeholder is caught here too
    expect(session('errors')->first('status'))->toBe(__('production.unmapped.alias_taken', ['stage' => 'Dyeing']))
        ->toContain('Dyeing')->not->toContain(':process')->not->toContain(':stage')
        ->and($stage->fresh()->status_aliases)->toBe(['RTF']);

    // including one claimed through a wildcard
    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$stage->id}/aliases", ['status' => 'SOMETHING CANCEL'])
        ->assertSessionHasErrors('status');
    expect(session('errors')->first('status'))->toContain('Closed / Cancelled');
});

it('leaves a lot whose stage was pinned by hand, and counts it separately', function () {
    $stage = Stage::where('code', 'RETURN')->firstOrFail();
    $hold = Stage::where('code', 'EXCEPTION')->firstOrFail();
    $plain = lotWithStatus('WEIRD ONE/');
    $pinned = lotWithStatus('WEIRD ONE/', [
        'stage_id' => $hold->id,
        'stage_overridden_at' => now(), 'stage_overridden_by' => $this->ppc->id,
    ]);

    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$stage->id}/aliases", ['status' => 'WEIRD ONE/'])
        ->assertRedirect();

    expect($plain->fresh()->stage_id)->toBe((int) $stage->id)
        ->and($pinned->fresh()->stage_id)->toBe((int) $hold->id)
        ->and($pinned->fresh()->stage_overridden_at)->not->toBeNull();
});

it('rejects an alias that says nothing', function (string $status) {
    $stage = Stage::where('code', 'RETURN')->firstOrFail();

    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$stage->id}/aliases", ['status' => $status])
        ->assertSessionHasErrors('status');

    expect($stage->fresh()->status_aliases)->toBe(['RTF']);
})->with([
    'blank' => [''],
    'spaces' => ['   '],
    'slashes' => [' / / '],
    'punctuation' => ['-- //'],
    'too long' => ['THIS STATUS IS FAR LONGER THAN THE COLUMN ALLOWS'],
]);

it('refuses to map onto a deactivated stage', function () {
    $stage = Stage::where('code', 'RETURN')->firstOrFail();
    Stage::query()->whereKey($stage->id)->update(['is_active' => false]);

    $this->actingAs($this->ppc)
        ->post("/production/unmapped-statuses/{$stage->id}/aliases", ['status' => 'WEIRD ONE/'])
        ->assertSessionHasErrors('stage_id');
});

it('refuses the mapping to anyone without masters.manage, before validating', function () {
    $stage = Stage::where('code', 'RETURN')->firstOrFail();

    $this->actingAs($this->viewer)
        ->post("/production/unmapped-statuses/{$stage->id}/aliases", ['status' => ['nonsense']])
        ->assertForbidden();

    expect($stage->fresh()->status_aliases)->toBe(['RTF']);
});

it('links the row to a lot carrying the status', function () {
    $first = lotWithStatus('WEIRD ONE/');
    lotWithStatus('WEIRD ONE/');

    $this->actingAs($this->ppc)->get('/production/unmapped-statuses')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('statuses.0.lot_id', $first->id)
            ->where('statuses.0.id', $first->id));
});
