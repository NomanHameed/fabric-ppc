<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\Production\Models\Contract;
use Database\Seeders\PartySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, PartySeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
});

it('lists parties with aliases and filters by normalised name', function () {
    $this->actingAs($this->ppc)->get('/masters/parties?q[name]=maria%25')->assertOk()
        ->assertInertia(fn ($page) => $page->component('masters/Parties')
            ->where('parties', fn ($rows) => collect($rows)->pluck('name')->contains('MARIA-B')
                && collect($rows)->firstWhere('name', 'MARIA-B')['aliases'] !== []));
});

/*
 * Parties are an Oracle-owned master (OracleOwnedMastersTest): the screen cannot create or delete one, and
 * cannot touch `oracle_code` or `name`. What it still owns — the aliases the floor's shorthand maps through,
 * plus `type` and `is_active` — is what these tests cover, through the update path that remains.
 */
it('adds aliases to a party, ignoring blanks, and resolves them by name', function () {
    $p = Party::query()->create(['oracle_code' => 999, 'name' => 'New Brand Ltd', 'type' => 'brand']);
    $this->actingAs($this->ppc)->put("/masters/parties/{$p->id}", [
        'oracle_code' => 999, 'name' => 'New Brand Ltd', 'type' => 'brand', 'is_active' => true, 'aliases' => ['NB', ' ', 'New-B'],
    ])->assertRedirect();
    expect($p->fresh()->aliases->pluck('alias')->sort()->values()->all())->toBe(['NB', 'New-B'])
        ->and(Party::findByName('nb')?->id)->toBe($p->id);
});

it('syncs aliases on update', function () {
    $p = Party::where('name', 'MARIA-B')->first();
    $this->actingAs($this->ppc)->put("/masters/parties/{$p->id}", [
        'name' => 'MARIA-B', 'type' => 'brand', 'is_active' => true, 'aliases' => ['MB'],
    ])->assertRedirect();
    expect($p->fresh()->aliases->pluck('alias')->all())->toBe(['MB']);
});

it('rejects an alias already used by another party', function () {
    $p = Party::where('name', 'BATIK')->first();
    $this->actingAs($this->ppc)->put("/masters/parties/{$p->id}", [
        'name' => 'BATIK', 'type' => 'brand', 'is_active' => true, 'aliases' => ['M-B'],
    ])->assertSessionHasErrors('aliases.0');
});

it('rejects duplicate aliases within the same request', function () {
    $p = Party::query()->create(['name' => 'Dup Brand', 'type' => 'brand']);
    $this->actingAs($this->ppc)->put("/masters/parties/{$p->id}", [
        'name' => 'Dup Brand', 'type' => 'brand', 'is_active' => true, 'aliases' => ['NB', 'nb '],
    ])->assertSessionHasErrors('aliases.1');
    expect($p->fresh()->aliases)->toBeEmpty();
});

it('refuses to delete a party, in use or not', function () {
    // While Oracle owns this master, PartyController's foreign-key check is never reached: the refusal comes
    // first and is the same whether or not the party is referenced. (`forms.in_use` itself is covered on an
    // app-owned master in MasterScreensTest; PartyController keeps the mapping for when parties are the
    // app's own again.)
    $busy = Party::create(['name' => 'Busy Party', 'type' => 'brand']);
    Contract::factory()->create(['party_id' => $busy->id]);
    $idle = Party::create(['name' => 'Idle Party', 'type' => 'brand']);

    foreach ([$busy, $idle] as $party) {
        $this->actingAs($this->ppc)->delete("/masters/parties/{$party->id}")
            ->assertSessionHasErrors(['record' => __('masters.oracle_owned.cannot_delete')]);
        expect(Party::whereKey($party->id)->exists())->toBeTrue();
    }
});
