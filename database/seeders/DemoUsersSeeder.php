<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Services\UserApprovalService;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * One verified demo login per non-admin role (the admin comes from AdminUserSeeder).
 * Seeded in every environment by design — deactivate these accounts before go-live.
 */
class DemoUsersSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const EMAIL_DOMAIN = 'fabric.local';

    /** Department code (FoundationSeeder) the dept_incharge demo user belongs to. */
    public const INCHARGE_DEPARTMENT_CODE = 'FINISH_STENTER';

    /** @var array<string, string> role => display name */
    public const USERS = [
        Permissions::ROLE_PPC => 'PPC Demo',
        Permissions::ROLE_MANAGER => 'Manager Demo',
        Permissions::ROLE_DEPT_INCHARGE => 'Department In-charge Demo',
        Permissions::ROLE_COLOUR_LAB => 'Colour Lab Demo',
        Permissions::ROLE_DISPATCH => 'Dispatch Demo',
        Permissions::ROLE_VIEWER => 'Viewer Demo',
    ];

    public static function emailFor(string $role): string
    {
        return $role.'@'.self::EMAIL_DOMAIN;
    }

    public function run(): void
    {
        // Model events are disabled during db:seed (WithoutModelEvents), so Spatie's automatic cache refresh doesn't run.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $inchargeDepartment = Department::query()->where('code', self::INCHARGE_DEPARTMENT_CODE)->firstOrFail();

        foreach (self::USERS as $role => $name) {
            // Only create when missing: re-running never resets a changed password or name.
            $user = User::firstOrCreate(
                ['email' => self::emailFor($role)],
                ['name' => $name, 'password' => self::PASSWORD, 'email_verified_at' => now(), 'is_active' => true],
            );
            if (! $user->isApproved()) {
                app(UserApprovalService::class)->approveOnCreation($user);
            }
            $user->syncRoles([$role]);

            if ($role === Permissions::ROLE_DEPT_INCHARGE) {
                $user->departments()->syncWithoutDetaching([$inchargeDepartment->id]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
