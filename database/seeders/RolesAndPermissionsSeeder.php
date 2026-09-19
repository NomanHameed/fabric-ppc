<?php

namespace Database\Seeders;

use App\Modules\Foundation\Access\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Model events are disabled during db:seed (WithoutModelEvents), so Spatie's automatic cache refresh doesn't run.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::ROLES as $roleName => $perms) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($perms === '*' ? Permissions::ALL : $perms);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
