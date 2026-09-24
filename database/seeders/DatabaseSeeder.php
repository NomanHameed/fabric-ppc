<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            FoundationSeeder::class,
            PartySeeder::class,
            RouteTemplateSeeder::class,
            AdminUserSeeder::class,
            DemoUsersSeeder::class,
            // The demo dataset. Idempotent, and it re-enables model events for itself (see DemoDataSeeder).
            DemoDataSeeder::class,
        ]);
    }
}
