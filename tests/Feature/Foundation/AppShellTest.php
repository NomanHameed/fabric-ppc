<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

it('renders the dashboard for every role', function (string $role) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->has('auth.permissions'));
})->with(['admin', 'ppc', 'manager', 'dept_incharge', 'colour_lab', 'dispatch', 'viewer']);

it('shares the users departments on the dashboard', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(FoundationSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('dept_incharge');
    $department = Department::where('code', 'FINISH_STENTER')->firstOrFail();
    $user->departments()->attach($department);

    $this->actingAs($user)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->where('auth.departments.0.code', 'FINISH_STENTER'));
});
