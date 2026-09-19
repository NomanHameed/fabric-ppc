<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\Production\Models\Lot;
use Database\Seeders\PartySeeder;

it('previews from the command line and refuses to commit with unresolved parties', function () {
    $this->seed(PartySeeder::class);
    $user = User::factory()->create();
    $this->artisan('oracle:import', ['path' => base_path('tests/Fixtures/oracle-wip-2026-09.xlsx'), '--user' => $user->id, '--commit' => true])
        ->expectsOutputToContain('New: 471')
        ->expectsOutputToContain('LENOK CREATIONS (PVT) LTD')
        ->assertExitCode(1);
    expect(Lot::count())->toBe(0);
});

it('commits from the command line once parties are linked', function () {
    $this->seed(PartySeeder::class);
    foreach ([397 => 'U&I GARMENTS', 485 => 'IMAGE', 561 => 'AL HAMD TEXTILE', 562 => 'LENOK CREATIONS'] as $code => $name) {
        Party::findByName($name)->update(['oracle_code' => $code]);
    }
    $user = User::factory()->create();
    $this->artisan('oracle:import', ['path' => base_path('tests/Fixtures/oracle-wip-2026-09.xlsx'), '--user' => $user->id, '--commit' => true])
        ->expectsOutputToContain('New: 623')
        ->assertExitCode(0);
    expect(Lot::count())->toBe(623);
});
