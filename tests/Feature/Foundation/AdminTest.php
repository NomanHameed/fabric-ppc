<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Setting;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class]);
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->ppc = User::factory()->create()->assignRole('ppc');
});

it('lets only admins manage users', function () {
    $this->actingAs($this->ppc)->get('/admin/users')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/users')->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Users')->has('users', 2)->has('roles', 7));
});

it('creates a department in-charge limited to departments', function () {
    $dept = Department::where('code', 'FINISH_STENTER')->first();
    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'Stenter Incharge', 'email' => 'stenter@fabric.local', 'password' => 'secret-pass-1',
        'is_active' => true, 'locale' => 'ur', 'roles' => ['dept_incharge'], 'department_ids' => [$dept->id],
    ])->assertRedirect();
    $u = User::where('email', 'stenter@fabric.local')->firstOrFail();
    expect($u->hasRole('dept_incharge'))->toBeTrue()
        ->and($u->departments->pluck('id')->all())->toBe([$dept->id])
        ->and($u->locale)->toBe('ur')
        ->and(Hash::check('secret-pass-1', $u->password))->toBeTrue();
});

it('keeps the password when left blank on update', function () {
    $old = $this->ppc->password;
    $this->actingAs($this->admin)->put("/admin/users/{$this->ppc->id}", [
        'name' => 'PPC Renamed', 'email' => $this->ppc->email, 'password' => '', 'is_active' => true, 'locale' => 'en',
        'roles' => ['ppc'], 'department_ids' => [],
    ])->assertRedirect();
    expect($this->ppc->fresh()->password)->toBe($old)->and($this->ppc->fresh()->name)->toBe('PPC Renamed');
});

it('stops an admin from locking themselves out', function () {
    $this->actingAs($this->admin)->put("/admin/users/{$this->admin->id}", [
        'name' => 'A', 'email' => $this->admin->email, 'is_active' => false, 'locale' => 'en', 'roles' => ['viewer'], 'department_ids' => [],
    ])->assertSessionHasErrors(['is_active', 'roles']);
});

it('edits role permissions except admin', function () {
    $this->actingAs($this->admin)->put('/admin/roles/viewer', ['permissions' => ['inquiry.view']])->assertRedirect();
    expect(Role::findByName('viewer')->permissions->pluck('name')->all())->toBe(['inquiry.view']);
    $this->actingAs($this->admin)->put('/admin/roles/admin', ['permissions' => []])->assertForbidden();
    $this->actingAs($this->admin)->put('/admin/roles/viewer', ['permissions' => ['made.up']])->assertSessionHasErrors('permissions.0');
});

it('404s for an unknown role', function () {
    $this->actingAs($this->admin)->put('/admin/roles/does-not-exist', ['permissions' => []])->assertNotFound();
});

it('stops a non-admin with admin.users.manage from granting or touching the admin role', function () {
    $this->ppc->givePermissionTo('admin.users.manage');

    $this->actingAs($this->ppc)->post('/admin/users', [
        'name' => 'Would-be Admin', 'email' => 'wannabe@fabric.local', 'password' => 'secret-pass-1',
        'is_active' => true, 'locale' => 'en', 'roles' => ['admin'], 'department_ids' => [],
    ])->assertForbidden();

    $this->actingAs($this->ppc)->put("/admin/users/{$this->admin->id}", [
        'name' => 'Renamed Admin', 'email' => $this->admin->email, 'is_active' => true, 'locale' => 'en',
        'roles' => ['admin'], 'department_ids' => [],
    ])->assertForbidden();

    $this->actingAs($this->ppc)->post('/admin/users', [
        'name' => 'Just A Viewer', 'email' => 'viewer-new@fabric.local', 'password' => 'secret-pass-1',
        'is_active' => true, 'locale' => 'en', 'roles' => ['viewer'], 'department_ids' => [],
    ])->assertRedirect();
    expect(User::where('email', 'viewer-new@fabric.local')->exists())->toBeTrue();
});

it('updates settings', function () {
    $this->actingAs($this->admin)->put('/admin/settings', ['company_name' => 'Albeen Textiles', 'idle_lot_alert_days' => 5])->assertRedirect();
    expect(Setting::get('company_name'))->toBe('Albeen Textiles')->and(Setting::get('idle_lot_alert_days'))->toBe(5);
});
