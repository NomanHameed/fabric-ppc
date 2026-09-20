<?php

use App\Models\User;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\LotRouteStep;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Illuminate\Support\Facades\Auth;

it('backfills lots that predate the ledger, once', function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $admin = User::factory()->create()->assignRole('admin');
    $lots = Lot::factory()->count(3)->create(['greige_qty' => 20]);
    config(['audit.console' => false]);

    $this->artisan('production:backfill-ledger')->expectsOutputToContain('Backfilled 3')->assertSuccessful();
    $this->artisan('production:backfill-ledger')->expectsOutputToContain('Backfilled 0')->assertSuccessful();

    expect(LotMovement::where('kind', 'import')->count())->toBe(3)
        ->and(LotMovement::whereNull('user_id')->count())->toBe(0)
        ->and($lots[0]->fresh()->routeSteps()->count())->toBeGreaterThan(0)
        ->and(LotMovement::first()->user_id)->toBe($admin->id)
        ->and((float) $lots[2]->fresh()->stock_qty)->toBe(20.0)
        // route steps are audited, and the command runs as the audit user
        ->and(LotRouteStep::first()->audits()->first()?->user_id)->toBe($admin->id)
        ->and(config('audit.console'))->toBeFalse()->and(Auth::user())->toBeNull();
});

it('records the movements on the given user and reports lots without greige', function () {
    $this->seed([FoundationSeeder::class, RouteTemplateSeeder::class]);
    $user = User::factory()->create();
    Lot::factory()->create(['greige_qty' => 5]);
    Lot::factory()->create(['greige_qty' => 0]);

    $this->artisan('production:backfill-ledger', ['--user' => $user->id])
        ->expectsOutputToContain('Backfilled 1 lot(s), 1 without greige')->assertSuccessful();
    expect(LotMovement::count())->toBe(1)->and(LotMovement::first()->user_id)->toBe($user->id);
});

it('fails without a user to record', function () {
    $this->artisan('production:backfill-ledger')->assertFailed();
});
