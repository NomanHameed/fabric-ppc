<?php

use App\Models\User;
use App\Modules\Foundation\Masters\OracleOwned;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\PartyAlias;
use App\Modules\Foundation\Models\Quality;
use App\Modules\OracleSync\Services\PartyResolver;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Oracle-owned masters (App\Modules\Foundation\Masters\OracleOwned). The client's rule: a master whose rows
 * come from the Oracle import is query-only on its own screen. The screen stays — browsing, F7/F8 and
 * record navigation keep working — but the write actions are refused, in the UI *and* here at the HTTP
 * boundary, so a crafted request gets the same answer as a disabled button.
 *
 * The refusal is about where the data comes from, not about permissions: every request below is made by a
 * PPC user who holds `masters.manage`. And it must stop at the screen — the Oracle import is the
 * legitimate writer of exactly these rows, so its own write paths are exercised here too.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->quality = Quality::query()->create(['oracle_code' => 5150, 'description' => '88X60/30X30 100% VISCOSE']);
    $this->party = Party::query()->create(['oracle_code' => 7731, 'name' => 'ALKARAM TEXTILE', 'type' => 'brand']);
});

it('keeps an Oracle-owned master browsable and queryable', function () {
    $this->actingAs($this->ppc)->get('/masters/qualities')->assertOk()
        ->assertInertia(fn ($page) => $page->component('masters/MasterForm')
            ->where('resource.oracleOwned', true)
            ->where('resource.appOwnedFields', ['is_active'])
            ->where('resource.canEdit', true) // the permission is still held: only the data's origin stops the write
            ->where('rows.0.oracle_code', 5150));

    $this->actingAs($this->ppc)->get('/masters/qualities?q[oracle_code]=5150')
        ->assertInertia(fn ($page) => $page->has('rows', 1));
    $this->actingAs($this->ppc)->get('/masters/qualities?q[oracle_code]=1')
        ->assertInertia(fn ($page) => $page->has('rows', 0));
});

it('refuses to create, change or delete a quality from its screen', function () {
    $this->actingAs($this->ppc)->post('/masters/qualities', ['oracle_code' => 1, 'description' => 'KEYED IN', 'is_active' => true])
        ->assertSessionHasErrors(['record' => __('masters.oracle_owned.cannot_create')]);
    expect(Quality::query()->where('oracle_code', 1)->exists())->toBeFalse();

    $this->actingAs($this->ppc)->put("/masters/qualities/{$this->quality->id}", [
        'oracle_code' => 5150, 'description' => 'EDITED', 'is_active' => false,
    ])->assertSessionHasErrors(['record' => __('masters.oracle_owned.cannot_update')]);
    expect($this->quality->fresh()->description)->toBe('88X60/30X30 100% VISCOSE');

    $this->actingAs($this->ppc)->delete("/masters/qualities/{$this->quality->id}")
        ->assertSessionHasErrors(['record' => __('masters.oracle_owned.cannot_delete')]);
    expect(Quality::query()->whereKey($this->quality->id)->exists())->toBeTrue();
});

it('lets the mill deactivate an obsolete quality without touching Oracle\'s own fields', function () {
    // `is_active` is the one field the import never writes — it only hides a quality from the LOVs, and the
    // live instance has 672 qualities with no description at all, so the mill has to be able to prune them.
    // The disabled Oracle inputs still post their current values, which is why they appear here unchanged.
    $this->actingAs($this->ppc)->put("/masters/qualities/{$this->quality->id}", [
        'oracle_code' => 5150, 'description' => '88X60/30X30 100% VISCOSE', 'is_active' => false,
    ])->assertSessionHasNoErrors();

    $fresh = $this->quality->fresh();
    expect($fresh->is_active)->toBeFalse()
        // Oracle's own columns, and everything QualityResource derives from the description, survive intact.
        ->and($fresh->oracle_code)->toBe(5150)
        ->and($fresh->description)->toBe('88X60/30X30 100% VISCOSE')
        ->and($fresh->composition)->toBe('100% VISCOSE');
});

it('refuses to create or delete a party from its screen', function () {
    $this->actingAs($this->ppc)->post('/masters/parties', ['name' => 'KEYED IN', 'type' => 'brand', 'is_active' => true, 'aliases' => []])
        ->assertSessionHasErrors(['record' => __('masters.oracle_owned.cannot_create')]);
    expect(Party::query()->where('name', 'KEYED IN')->exists())->toBeFalse();

    $this->actingAs($this->ppc)->delete("/masters/parties/{$this->party->id}")
        ->assertSessionHasErrors(['record' => __('masters.oracle_owned.cannot_delete')]);
    expect(Party::query()->whereKey($this->party->id)->exists())->toBeTrue();
});

it('refuses a party update that would change the Oracle code or the name', function () {
    $this->actingAs($this->ppc)->put("/masters/parties/{$this->party->id}", [
        'oracle_code' => 7731, 'name' => 'ALKARAM TEXTILE LTD', 'type' => 'brand', 'is_active' => true, 'aliases' => [],
    ])->assertSessionHasErrors(['record' => __('masters.oracle_owned.cannot_update')]);

    $this->actingAs($this->ppc)->put("/masters/parties/{$this->party->id}", [
        'oracle_code' => 9999, 'name' => 'ALKARAM TEXTILE', 'type' => 'brand', 'is_active' => true, 'aliases' => [],
    ])->assertSessionHasErrors(['record' => __('masters.oracle_owned.cannot_update')]);

    expect($this->party->fresh()->only(['oracle_code', 'name']))->toBe(['oracle_code' => 7731, 'name' => 'ALKARAM TEXTILE']);
});

it('still saves the party fields the mill owns: aliases, type and the active flag', function () {
    $this->actingAs($this->ppc)->put("/masters/parties/{$this->party->id}", [
        'oracle_code' => 7731, 'name' => 'ALKARAM TEXTILE', 'type' => 'local', 'is_active' => false, 'aliases' => ['AK', 'AL-KARAM'],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $party = $this->party->fresh();
    expect($party->type)->toBe('local')
        ->and($party->is_active)->toBeFalse()
        ->and($party->aliases->pluck('alias')->sort()->values()->all())->toBe(['AK', 'AL-KARAM'])
        ->and($party->oracle_code)->toBe(7731)
        ->and($party->name)->toBe('ALKARAM TEXTILE');
});

it('saves the stored value for an Oracle-owned field rather than trusting the form', function () {
    // A disabled input still posts its value, and `7731` arrives as the string the form sent. The payload
    // has to stay complete — a resource's fill() may need every field — so the Oracle-owned keys survive,
    // but carrying the *stored* value: the form's copy is never what reaches the column.
    $data = OracleOwned::assertCanUpdate('parties', $this->party, [
        'oracle_code' => '7731', 'name' => 'ALKARAM TEXTILE', 'type' => 'brand', 'is_active' => true, 'aliases' => ['AK'],
    ]);

    expect(array_keys($data))->toBe(['oracle_code', 'name', 'type', 'is_active', 'aliases'])
        ->and($data['oracle_code'])->toBe($this->party->oracle_code)
        ->and($data['oracle_code'])->not->toBeString()
        ->and($data['name'])->toBe($this->party->name)
        // and the app-owned fields are still the submitted ones, not the stored ones
        ->and($data['aliases'])->toBe(['AK']);
});

it('leaves the Oracle import free to create a party and a quality', function () {
    // The single most important constraint: the lock is on the screen, not on the row.
    $quality = Quality::fromOracle(6001, '92X88/40X40 100% COTTON');
    expect($quality->exists)->toBeTrue()->and($quality->ends)->toBe(92);
    // ...including the update the import does on every re-import of the same code.
    expect(Quality::fromOracle(6001, '92X88/40X40 100% COTTON CORRECTED')->description)->toBe('92X88/40X40 100% COTTON CORRECTED');

    $resolver = app(PartyResolver::class);
    $created = $resolver->create(8001, 'NEW IMPORT PARTY', 'brand');
    expect($created->exists)->toBeTrue()->and($created->oracle_code)->toBe(8001);

    // And the resolve-party flow's other branch: linking an existing party to an Oracle code, with the
    // export's own spelling kept as an alias.
    $unlinked = Party::query()->create(['name' => 'LOCAL SHORTHAND', 'type' => 'local']);
    $linked = $resolver->link(8002, 'LOCAL  SHORTHAND LTD', $unlinked);
    expect($linked->oracle_code)->toBe(8002)
        ->and($linked->aliases()->count())->toBe(1);

    // The back-fill ImportCommitService does on a name-matched party.
    $linked->update(['oracle_code' => 8003]);
    expect($linked->fresh()->oracle_code)->toBe(8003);
});

it('leaves app-owned masters fully writable', function () {
    $this->actingAs($this->ppc)->get('/masters/departments')
        ->assertInertia(fn ($page) => $page->where('resource.oracleOwned', false)->where('resource.appOwnedFields', []));

    $this->actingAs($this->ppc)->post('/masters/departments', ['code' => 'QA', 'name_en' => 'Quality', 'sort' => 130, 'is_active' => true])
        ->assertSessionHasNoErrors()->assertRedirect();
});

it('leaves machines and operations editable because nothing else can create them', function () {
    // Oracle has COST_CENTRES and PROCESSING_OPERATIONS, but this application has no importer for either:
    // these screens are the only way a machine or an operation can be added, so they are not locked (yet).
    expect(OracleOwned::owns('machines'))->toBeFalse()
        ->and(OracleOwned::owns('operations'))->toBeFalse();

    $this->actingAs($this->ppc)->post('/masters/machines', [
        'code' => 'CURE-9', 'name' => 'Curing Oven 9', 'is_active' => true,
        'departments' => [Department::query()->value('id')],
    ])->assertSessionHasNoErrors()->assertRedirect();
});

it('keeps alias deletion working on a locked party', function () {
    $alias = $this->party->aliases()->create(['alias' => 'AK']);

    $this->actingAs($this->ppc)->put("/masters/parties/{$this->party->id}", [
        'oracle_code' => 7731, 'name' => 'ALKARAM TEXTILE', 'type' => 'brand', 'is_active' => true, 'aliases' => [],
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(PartyAlias::query()->whereKey($alias->id)->exists())->toBeFalse();
});
