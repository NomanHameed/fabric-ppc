<?php

use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Models\Department;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->roles = array_keys(DemoUsersSeeder::USERS);
    $this->seed(DatabaseSeeder::class);
});

it('seeds one verified, active demo user per non-admin role', function () {
    $demo = User::query()->where('email', 'like', '%@fabric.local')->where('email', '!=', env('ADMIN_EMAIL', 'admin@fabric.local'))->get();

    expect($demo)->toHaveCount(6)
        ->and($demo->pluck('email')->sort()->values()->all())
        ->toBe(collect($this->roles)->map(fn ($r) => "{$r}@fabric.local")->sort()->values()->all());

    foreach ($this->roles as $role) {
        $user = User::where('email', "{$role}@fabric.local")->firstOrFail();
        expect($user->getRoleNames()->all())->toBe([$role], "{$role} has exactly one role")
            ->and($user->name)->toBe(DemoUsersSeeder::USERS[$role])
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->is_active)->toBeTrue()
            ->and(Hash::check('password', $user->password))->toBeTrue();
    }
});

it('links only the department in-charge to Finish-Stenter', function () {
    $finishStenter = Department::where('code', 'FINISH_STENTER')->firstOrFail();
    $incharge = User::where('email', 'dept_incharge@fabric.local')->firstOrFail();

    expect($incharge->departments()->pluck('code')->all())->toBe([$finishStenter->code]);

    foreach (array_diff($this->roles, ['dept_incharge']) as $role) {
        expect(User::where('email', "{$role}@fabric.local")->firstOrFail()->departments()->count())->toBe(0, "{$role} has no departments");
    }
    expect(User::where('email', env('ADMIN_EMAIL', 'admin@fabric.local'))->firstOrFail()->departments()->count())->toBe(0);
});

it('is idempotent and never resets a changed password', function () {
    $before = User::count();
    $viewer = User::where('email', 'viewer@fabric.local')->firstOrFail();
    $viewer->forceFill(['password' => 'changed-secret'])->save();

    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe($before)
        ->and(User::where('email', 'like', '%@fabric.local')->count())->toBe(7)
        ->and(Hash::check('changed-secret', $viewer->fresh()->password))->toBeTrue()
        ->and(User::where('email', 'dept_incharge@fabric.local')->firstOrFail()->departments()->count())->toBe(1);

    foreach ($this->roles as $role) {
        expect(User::where('email', "{$role}@fabric.local")->firstOrFail()->getRoleNames()->all())->toBe([$role]);
    }
});

it('lets every demo user log in and shares that role\'s permissions', function () {
    foreach ($this->roles as $role) {
        $this->post(route('login.store'), ['email' => "{$role}@fabric.local", 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs(User::where('email', "{$role}@fabric.local")->firstOrFail());

        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->where('auth.roles', [$role])
            ->where('auth.permissions', fn ($perms) => count($perms) === count(Permissions::ROLES[$role])
                && collect($perms)->sort()->values()->all() === collect(Permissions::ROLES[$role])->sort()->values()->all()));

        $this->post(route('logout'));
        $this->assertGuest();
    }
});
