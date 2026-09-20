<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create()->assignRole('admin');
});

it('creates admin-added users as verified (M-1)', function () {
    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'New', 'email' => 'new@fabric.local', 'password' => 'password123', 'is_active' => true, 'locale' => 'en', 'roles' => ['ppc'],
    ])->assertRedirect();
    expect(User::where('email', 'new@fabric.local')->firstOrFail()->email_verified_at)->not->toBeNull();
});

it('lets a non-admin user manager save their own record (M-2)', function () {
    $manager = User::factory()->create(['locale' => 'en'])->assignRole('ppc');
    $manager->givePermissionTo('admin.users.manage');
    $this->actingAs($manager)->put("/admin/users/{$manager->id}", [
        'name' => 'Renamed', 'email' => $manager->email, 'is_active' => true, 'locale' => 'en', 'roles' => ['ppc'],
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect($manager->fresh()->name)->toBe('Renamed');
});

it('still stops an admin dropping their own admin role (M-2)', function () {
    $this->actingAs($this->admin)->put("/admin/users/{$this->admin->id}", [
        'name' => $this->admin->name, 'email' => $this->admin->email, 'is_active' => true, 'locale' => 'en', 'roles' => ['ppc'],
    ])->assertSessionHasErrors('roles');
});

it('refuses new admin.* permissions from a non-admin roles manager (M-3)', function () {
    $manager = User::factory()->create()->assignRole('ppc');
    $manager->givePermissionTo('admin.roles.manage');
    $this->actingAs($manager)->put('/admin/roles/viewer', ['permissions' => ['inquiry.view', 'admin.users.manage']])
        ->assertSessionHasErrors('permissions');
    expect(Role::findByName('viewer')->hasPermissionTo('admin.users.manage'))->toBeFalse();

    $this->actingAs($this->admin)->put('/admin/roles/viewer', ['permissions' => ['inquiry.view', 'admin.users.manage']])
        ->assertSessionHasNoErrors();
    expect(Role::findByName('viewer')->hasPermissionTo('admin.users.manage'))->toBeTrue();
});

it('lets a non-admin roles manager keep or drop an existing admin.* permission but not add one (M-3)', function () {
    Role::findByName('viewer')->syncPermissions(['inquiry.view', 'admin.settings.manage']);
    $manager = User::factory()->create()->assignRole('ppc');
    $manager->givePermissionTo('admin.roles.manage');

    $this->actingAs($manager)->put('/admin/roles/viewer', ['permissions' => ['inquiry.view', 'admin.settings.manage', 'audit.view']])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(Role::findByName('viewer')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['admin.settings.manage', 'audit.view', 'inquiry.view']);

    $this->actingAs($manager)->put('/admin/roles/viewer', ['permissions' => ['inquiry.view', 'admin.settings.manage', 'admin.users.manage']])
        ->assertSessionHasErrors('permissions');
    expect(Role::findByName('viewer')->hasPermissionTo('admin.users.manage'))->toBeFalse();

    $this->actingAs($manager)->put('/admin/roles/viewer', ['permissions' => ['inquiry.view']])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(Role::findByName('viewer')->permissions->pluck('name')->all())->toBe(['inquiry.view']);
});

it('lets a non-admin roles manager save non-admin permissions (M-3)', function () {
    $manager = User::factory()->create()->assignRole('ppc');
    $manager->givePermissionTo('admin.roles.manage');
    $this->actingAs($manager)->put('/admin/roles/viewer', ['permissions' => ['inquiry.view', 'audit.view']])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(Role::findByName('viewer')->permissions->pluck('name')->sort()->values()->all())->toBe(['audit.view', 'inquiry.view']);
});

it('stops a non-admin user manager deactivating themselves (M-2)', function () {
    $manager = User::factory()->create(['locale' => 'en'])->assignRole('ppc');
    $manager->givePermissionTo('admin.users.manage');
    $this->actingAs($manager)->put("/admin/users/{$manager->id}", [
        'name' => $manager->name, 'email' => $manager->email, 'is_active' => false, 'locale' => 'en', 'roles' => ['ppc'],
    ])->assertSessionHasErrors(['is_active' => __('admin.cannot_deactivate_self')]);
    expect($manager->fresh()->is_active)->toBeTrue();
});

it('blocks an inactive user at login (M-4)', function () {
    $user = User::factory()->create(['is_active' => false]);
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.inactive')]);
    $this->assertGuest();
});

it('still logs an active user in with the right password (M-4)', function () {
    $user = User::factory()->create();
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

it('blocks an inactive user at passkey login (M-4)', function () {
    $passkey = (new Passkey)->setRelation('user', User::factory()->create(['is_active' => false]));
    try {
        Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey);
        $this->fail('Expected the passkey authorization callback to reject an inactive user.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['credential' => [__('auth.inactive')]]);
    }
});

it('still allows an active user through passkey login (M-4)', function () {
    $passkey = (new Passkey)->setRelation('user', User::factory()->create());
    expect(Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey))->toBeTrue();
});

it('does not expose email addresses through the users LOV (M-10)', function () {
    $user = User::factory()->create(['name' => 'Zed Example', 'email' => 'zed@example.com']);
    $res = $this->actingAs($this->admin)->getJson('/lov/users?q=zed')->assertOk()->json();
    expect(json_encode($res))->not->toContain('zed@example.com')
        ->and(collect($res['data'])->firstWhere('id', $user->id)['label'])->toBe('Zed Example');

    expect($this->actingAs($this->admin)->getJson('/lov/users?q=example.com')->assertOk()->json('data'))->toBe([])
        ->and($this->actingAs($this->admin)->getJson('/lov/users?exact=zed@example.com')->assertOk()->json('data'))->toBe([]);
});
