<?php

use App\Models\User;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAccessSeeder;
use Database\Seeders\DemoCapacitySeeder;
use Database\Seeders\DemoUsersSeeder;
use Spatie\Permission\Models\Role;

it('seeds the whole database through DatabaseSeeder', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Role::findByName('ppc')->permissions)->not->toBeEmpty()
        ->and(User::where('email', env('ADMIN_EMAIL', 'admin@fabric.local'))->first()?->hasRole('admin'))->toBeTrue();
});

/*
 * HANDOFF §13 tells an operator to run `php artisan migrate --force --seed` on the first install. That must
 * produce reference data and an administrator — and nothing else. It used to also write eight `@signup.test`
 * logins and six `@fabric.local` role logins, all with the password `password`, plus 24 fake lots whose
 * lot_movements are insert-only and therefore could never be removed from the mill's live database.
 */
it('seeds no demo accounts and no demo lots in production', function () {
    config(['app.env' => 'production']);

    $this->seed(DatabaseSeeder::class);

    expect(Lot::query()->count())->toBe(0)
        ->and(LotMovement::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'like', '%@'.DemoAccessSeeder::DOMAIN)->count())->toBe(0)
        ->and(User::query()->where('email', 'like', '%@'.DemoUsersSeeder::EMAIL_DOMAIN)
            ->where('email', '!=', env('ADMIN_EMAIL', 'admin@fabric.local'))->count())->toBe(0);
});

it('still seeds the reference data and the administrator in production', function () {
    config(['app.env' => 'production']);

    $this->seed(DatabaseSeeder::class);

    expect(Role::findByName('ppc')->permissions)->not->toBeEmpty()
        ->and(Machine::query()->count())->toBeGreaterThan(0)
        ->and(User::where('email', env('ADMIN_EMAIL', 'admin@fabric.local'))->first()?->hasRole('admin'))->toBeTrue();
});

/*
 * docs/setup.md §6 says re-seeding is safe. It was not: DemoCapacitySeeder force-filled a capacity onto the
 * ten real machines on every run, so one `db:seed` wiped whatever PPC had entered on the Machines master.
 */
it('does not overwrite a machine capacity somebody has already entered', function () {
    $this->seed(DatabaseSeeder::class);

    $machine = Machine::query()->where('code', 'STENTER-1')->firstOrFail();
    $machine->forceFill(['daily_capacity' => 12345, 'hourly_capacity' => null])->save();

    $this->seed(DemoCapacitySeeder::class);

    expect((float) $machine->fresh()->daily_capacity)->toBe(12345.0);
});
