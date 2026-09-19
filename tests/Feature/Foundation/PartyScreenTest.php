<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
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

it('creates a party with aliases', function () {
    $this->actingAs($this->ppc)->post('/masters/parties', [
        'oracle_code' => 999, 'name' => 'New Brand Ltd', 'type' => 'brand', 'is_active' => true, 'aliases' => ['NB', ' ', 'New-B'],
    ])->assertRedirect();
    $p = Party::where('oracle_code', 999)->firstOrFail();
    expect($p->aliases->pluck('alias')->sort()->values()->all())->toBe(['NB', 'New-B'])
        ->and(Party::findByName('nb')?->id)->toBe($p->id);
});

it('syncs aliases on update', function () {
    $p = Party::where('name', 'MARIA-B')->first();
    $this->actingAs($this->ppc)->put("/masters/parties/{$p->id}", [
        'oracle_code' => 394, 'name' => 'MARIA-B', 'type' => 'brand', 'is_active' => true, 'aliases' => ['MB'],
    ])->assertRedirect();
    expect($p->fresh()->aliases->pluck('alias')->all())->toBe(['MB'])->and($p->fresh()->oracle_code)->toBe(394);
});

it('rejects an alias already used by another party', function () {
    $p = Party::where('name', 'BATIK')->first();
    $this->actingAs($this->ppc)->put("/masters/parties/{$p->id}", [
        'name' => 'BATIK', 'type' => 'brand', 'is_active' => true, 'aliases' => ['M-B'],
    ])->assertSessionHasErrors('aliases.0');
});

it('rejects duplicate aliases within the same request', function () {
    $this->actingAs($this->ppc)->post('/masters/parties', [
        'name' => 'Dup Brand', 'type' => 'brand', 'is_active' => true, 'aliases' => ['NB', 'nb '],
    ])->assertSessionHasErrors('aliases.1');
    expect(Party::where('name', 'Dup Brand')->exists())->toBeFalse();
});

it('refuses to delete a party that has contracts, reporting it as in use', function () {
    $party = Party::create(['name' => 'Busy Party', 'type' => 'brand']);
    App\Modules\Production\Models\Contract::factory()->create(['party_id' => $party->id]);

    $this->actingAs($this->ppc)->delete("/masters/parties/{$party->id}")->assertSessionHasErrors(['record' => __('forms.in_use')]);

    expect(Party::whereKey($party->id)->exists())->toBeTrue();
});
