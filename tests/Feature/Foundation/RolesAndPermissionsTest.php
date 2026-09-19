<?php

use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('creates the seven roles', function () {
    expect(Role::pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'colour_lab', 'dept_incharge', 'dispatch', 'manager', 'ppc', 'viewer']);
});

it('gives ppc all-department sheet editing and imports', function () {
    $ppc = Role::findByName('ppc');
    expect($ppc->hasPermissionTo('production.sheet.edit.all'))->toBeTrue()
        ->and($ppc->hasPermissionTo('oracle.import.run'))->toBeTrue();
});

it('limits department in-charge to own department editing', function () {
    $role = Role::findByName('dept_incharge');
    expect($role->hasPermissionTo('production.sheet.edit.own_department'))->toBeTrue()
        ->and($role->hasPermissionTo('production.sheet.edit.all'))->toBeFalse();
});

it('does not let a viewer run imports', function () {
    $user = User::factory()->create();
    $user->assignRole('viewer');
    expect($user->can('oracle.import.run'))->toBeFalse()
        ->and($user->can('inquiry.view'))->toBeTrue();
});

it('lets admin pass every gate', function () {
    $user = User::factory()->create();
    $user->assignRole(Permissions::ROLE_ADMIN);
    expect($user->can('masters.manage'))->toBeTrue()
        ->and($user->can('anything.not.declared'))->toBeTrue();
});

it('logs out inactive users', function () {
    $user = User::factory()->create(['is_active' => false]);
    $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

it('shares roles and permissions with every page', function () {
    $user = User::factory()->create();
    $user->assignRole('viewer');
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('auth.roles', ['viewer'])
        ->where('auth.permissions', fn ($perms) => collect($perms)->contains('inquiry.view')
            && ! collect($perms)->contains('oracle.import.run')));
});

it('shares the full catalogue for admins', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('auth.permissions', fn ($perms) => collect($perms)->count() === count(Permissions::ALL)));
});
