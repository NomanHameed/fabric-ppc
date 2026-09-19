<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@fabric.local')],
            ['name' => 'Administrator', 'password' => env('ADMIN_PASSWORD', 'password'), 'email_verified_at' => now()],
        );
        $admin->assignRole(Permissions::ROLE_ADMIN);
    }
}
