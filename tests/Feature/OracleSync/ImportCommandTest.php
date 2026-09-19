<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Models\OracleImport;
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

it('fails cleanly when the given --user id does not exist', function () {
    $this->artisan('oracle:import', ['path' => base_path('tests/Fixtures/oracle-wip-2026-09.xlsx'), '--user' => 999999])
        ->expectsOutputToContain('No user found')
        ->assertExitCode(1);
});

it('fails cleanly when no --user is given and no admin exists', function () {
    $this->artisan('oracle:import', ['path' => base_path('tests/Fixtures/oracle-wip-2026-09.xlsx')])
        ->expectsOutputToContain('No user found')
        ->assertExitCode(1);
});

it('reports the stale-preview domain error and exits 1 instead of crashing', function () {
    $this->seed(PartySeeder::class);
    foreach ([397 => 'U&I GARMENTS', 485 => 'IMAGE', 561 => 'AL HAMD TEXTILE', 562 => 'LENOK CREATIONS'] as $code => $name) {
        Party::findByName($name)->update(['oracle_code' => $code]);
    }
    $user = User::factory()->create();

    // A "committed after this preview" import, with a committed_at timestamp that will
    // be later than the preview the command is about to create: this makes
    // ImportCommitService::commit() throw the real stale DomainException without
    // needing to mock the (final) service class.
    OracleImport::create([
        'label' => 'blocking-import.xlsx', 'uploaded_by' => $user->id,
        'status' => 'committed', 'committed_at' => now()->addMinute(),
    ]);

    $this->artisan('oracle:import', ['path' => base_path('tests/Fixtures/oracle-wip-2026-09.xlsx'), '--user' => $user->id, '--commit' => true])
        ->expectsOutputToContain(__('oracle.errors.stale'))
        ->assertExitCode(1);
    expect(Lot::count())->toBe(0);
});
