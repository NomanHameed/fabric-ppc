<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Reference data (roles, departments, machines, operations, shifts, lookups, parties, route templates) and
     * the administrator account are seeded everywhere: the go-live checklist runs `migrate --force --seed` on
     * the first install (HANDOFF §13) and the application does not work without them.
     *
     * The demo material is not. `DemoUsersSeeder` creates one login per role with the password `password`, and
     * `DemoDataSeeder` writes fake signups, 24 fake lots with real `lot_movements` / `lot_balances` posted
     * through LedgerService — insert-only, so unremovable — fake machine capacities and the mocked Oracle rows.
     * On a live database that is both a security hole and figures nobody can delete, so both are skipped when
     * `APP_ENV=production`. They are still available deliberately:
     *
     *     php artisan db:seed --class=DemoUsersSeeder
     *     php artisan db:seed --class=DemoDataSeeder
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            FoundationSeeder::class,
            PartySeeder::class,
            RouteTemplateSeeder::class,
            AdminUserSeeder::class,
        ]);

        // config('app.env'), not app()->environment(): the container's `env` value is fixed at bootstrap, so
        // reading it would make this untestable without a second application instance.
        if (config('app.env') === 'production') {
            $this->command->info('Skipping the demo seeders: APP_ENV is production.');

            return;
        }

        $this->call([
            DemoUsersSeeder::class,
            // The demo dataset. Idempotent, and it re-enables model events for itself (see DemoDataSeeder).
            DemoDataSeeder::class,
        ]);
    }
}
